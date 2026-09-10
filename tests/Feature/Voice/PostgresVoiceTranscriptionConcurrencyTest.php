<?php

declare(strict_types=1);

use App\Enums\ChannelAccountStatus;
use App\Enums\ChannelType;
use App\Enums\MessageDirection;
use App\Enums\MessageProcessingStatus;
use App\Enums\MessageType;
use App\Enums\PlanFeature;
use App\Enums\SubscriptionStatus;
use App\Enums\TranscriptionFailureReason;
use App\Enums\TranscriptionStatus;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * GENUINE parallel tests for voice transcription on PostgreSQL — separate PHP
 * processes, real row locks, no shared transaction.
 *
 * Everything here is ultimately about ONE number: how many times
 * `/audio/transcriptions` was actually called. Transcription is a paid external
 * read, so what must hold under concurrency is:
 *
 *  - a duplicate webhook yields ONE audio message, not two paid pieces of work;
 *  - of many workers, exactly one owns a voice note at a time;
 *  - a worker whose claim was swept and re-issued cannot reach the provider,
 *    and cannot spend an attempt on the way there;
 *  - the two-attempt ceiling survives racing sweepers and workers;
 *  - a persisted transcript is never produced, or paid for, a second time.
 *
 * Not wrapped in RefreshDatabase: it removes only the rows it created.
 */
beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Real concurrency test requires the pgsql connection.');
    }

    try {
        DB::connection()->getPdo();
    } catch (Throwable) {
        test()->markTestSkipped('PostgreSQL is not reachable.');
    }
});

/**
 * One subscriber on a voice-enabled plan, with a WhatsApp account and an open
 * conversation — created for real so separate processes can see it.
 *
 * @return array{0: User, 1: ChannelAccount, 2: Conversation}
 */
function pvSubject(): array
{
    $plan = Plan::create([
        'name' => 'Voice Probe',
        'slug' => 'voice-probe-'.bin2hex(random_bytes(5)),
        'price' => 0,
        'currency' => 'ILS',
        'billing_period' => 'monthly',
        'trial_days' => 0,
        'limits' => ['ai_reply' => ['daily' => 1000, 'monthly' => 10000, 'weight' => 1]],
        'features' => [PlanFeature::Voice->value => true],
        'is_active' => true,
        'is_default' => false,
        'sort_order' => 0,
    ]);

    $user = User::factory()->create([
        'is_admin' => false,
        'locale' => 'ar',
        'email' => 'voice-probe-'.bin2hex(random_bytes(6)).'@example.test',
    ]);

    Subscription::create([
        'subscriber_id' => $user->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'started_at' => now(),
        'current_period_start' => now(),
        'current_period_end' => now()->addMonth(),
    ]);

    $account = ChannelAccount::factory()->for($user)->create([
        'channel' => ChannelType::WhatsApp,
        'external_identifier' => '+97059'.random_int(1000000, 9999999),
        'status' => ChannelAccountStatus::Active,
    ]);

    $conversation = Conversation::factory()->for($user)->create(['channel_account_id' => $account->id]);

    return [$user, $account, $conversation];
}

function pvVoiceNote(User $user, Conversation $conversation, array $attrs = []): Message
{
    return Message::query()->create(array_merge([
        'conversation_id' => $conversation->id,
        'user_id' => $user->id,
        'direction' => MessageDirection::Inbound,
        'type' => MessageType::Audio,
        'external_message_id' => 'wamid.pv'.bin2hex(random_bytes(6)),
        'text_content' => null,
        'metadata' => ['provider' => 'whatsapp', 'voice' => true],
        'processing_status' => MessageProcessingStatus::Queued,
        'voice_media_id' => 'probe-media',
        'voice_mime_type' => 'audio/ogg; codecs=opus',
        'transcription_status' => TranscriptionStatus::Pending,
        'transcription_attempts' => 0,
    ], $attrs));
}

/** @param list<string> $args */
function pvRun(array $args): Process
{
    $p = new Process(['php', 'artisan', 'sanad:voice-transcription-probe', ...$args], base_path());
    $p->start();

    return $p;
}

/**
 * @param  list<Process>  $processes
 * @return list<string>
 */
function pvOutcomes(array $processes): array
{
    $outcomes = [];

    foreach ($processes as $p) {
        $p->wait();
        expect($p->getExitCode())->toBe(0, $p->getOutput().$p->getErrorOutput());
        $outcomes[] = trim($p->getOutput());
    }

    return $outcomes;
}

/** How many of these processes actually reached the transcription provider. */
function pvPaidRequests(array $outcomes): int
{
    return array_sum(array_map(
        static fn (string $o): int => (int) (explode(':', $o)[2] ?? 0),
        $outcomes,
    ));
}

function pvCleanup(User $user): void
{
    DB::table('usage_events')->where('user_id', $user->id)->delete();
    DB::table('messages')->where('user_id', $user->id)->delete();
    DB::table('conversations')->where('user_id', $user->id)->delete();
    DB::table('channel_accounts')->where('user_id', $user->id)->delete();
    $planIds = DB::table('subscriptions')->where('subscriber_id', $user->id)->pluck('plan_id');
    DB::table('subscriptions')->where('subscriber_id', $user->id)->delete();
    DB::table('webhook_events')->where('provider', 'whatsapp')->where('created_at', '>=', CarbonImmutable::now()->subMinutes(10))->delete();
    $user->delete();
    DB::table('plans')->whereIn('id', $planIds)->delete();
}

/*
|--------------------------------------------------------------------------
| Ingestion
|--------------------------------------------------------------------------
*/

it('of 6 concurrent deliveries of the same voice note, exactly one audio message exists', function () {
    [$user, $account] = pvSubject();
    $wamid = 'wamid.pv'.bin2hex(random_bytes(6));
    $from = ltrim($account->external_identifier, '+');

    try {
        pvOutcomes(array_map(fn () => pvRun(['ingest', $wamid, $from]), range(1, 6)));

        // One message means one piece of paid work later. Two would mean the
        // subscriber is charged twice for speaking once.
        expect(DB::table('messages')->where('external_message_id', $wamid)->count())->toBe(1)
            ->and(DB::table('messages')->where('user_id', $user->id)->count())->toBe(1);

        $row = DB::table('messages')->where('external_message_id', $wamid)->first();

        expect($row->type)->toBe(MessageType::Audio->value)
            ->and($row->voice_media_id)->toBe('probe-media')
            ->and($row->transcription_status)->toBe(TranscriptionStatus::Pending->value)
            ->and((int) $row->transcription_attempts)->toBe(0)
            // Ingestion never calls a provider; it only makes the message exist.
            ->and($row->text_content)->toBeNull();
    } finally {
        pvCleanup($user);
    }
});

/*
|--------------------------------------------------------------------------
| Ownership
|--------------------------------------------------------------------------
*/

it('of 6 concurrent claims of one voice note exactly one wins, and no claim spends an attempt', function () {
    [$user, , $conversation] = pvSubject();
    $message = pvVoiceNote($user, $conversation);

    try {
        $outcomes = pvOutcomes(array_map(fn () => pvRun(['claim', (string) $message->id]), range(1, 6)));

        $winners = array_filter($outcomes, static fn (string $o): bool => preg_match('/^claimed:[0-9a-f]{32}$/', $o) === 1);
        $refused = array_filter($outcomes, static fn (string $o): bool => str_starts_with($o, 'claimed::'));

        $message->refresh();

        expect($winners)->toHaveCount(1, implode(' | ', $outcomes))
            ->and($refused)->toHaveCount(5, implode(' | ', $outcomes))
            ->and($message->transcription_status)->toBe(TranscriptionStatus::Processing)
            // A claim is not a request: the retry budget is untouched by it.
            ->and($message->transcription_attempts)->toBe(0)
            ->and($message->transcription_dispatched_at)->toBeNull();
    } finally {
        pvCleanup($user);
    }
});

it('of 6 concurrent workers holding ONE claim only one reaches the provider', function () {
    [$user, , $conversation] = pvSubject();
    $message = pvVoiceNote($user, $conversation);

    try {
        $claimed = pvOutcomes([pvRun(['claim', (string) $message->id])])[0];
        $token = explode(':', $claimed)[1];

        $outcomes = pvOutcomes(array_map(
            fn () => pvRun(['dispatch', (string) $message->id, $token, '--sleep=50000']),
            range(1, 6),
        ));

        $message->refresh();
        $report = implode(' | ', $outcomes);

        // One claim authorises ONE paid request, however many workers hold it.
        expect(pvPaidRequests($outcomes))->toBe(1, $report)
            ->and($message->transcription_attempts)->toBe(1, $report)
            ->and($message->transcription_status)->toBe(TranscriptionStatus::Transcribed, $report)
            ->and($message->text_content)->not->toBeNull()
            // One transcript, and one pair of ledger rows for the one request.
            ->and(DB::table('usage_events')->where('correlation_id', 'message:'.$message->id)->count())->toBe(2);
    } finally {
        pvCleanup($user);
    }
});

it('a worker whose claim was swept and re-issued cannot reach the provider', function () {
    [$user, , $conversation] = pvSubject();
    $message = pvVoiceNote($user, $conversation);

    try {
        // A worker takes the claim, then stalls.
        $stale = explode(':', pvOutcomes([pvRun(['claim', (string) $message->id])])[0])[1];

        // Its lease expires and a second worker legitimately takes over.
        DB::table('messages')->where('id', $message->id)
            ->update(['transcription_claimed_at' => CarbonImmutable::now()->subSeconds(3600)]);

        $fresh = explode(':', pvOutcomes([pvRun(['claim', (string) $message->id])])[0])[1];
        expect($fresh)->not->toBe($stale);

        // The stalled worker now wakes up and tries to carry on.
        $zombie = pvOutcomes([pvRun(['dispatch', (string) $message->id, $stale])])[0];

        $message->refresh();

        // It is refused BEFORE the provider and BEFORE the attempt counter —
        // which is the only place that refusal is worth anything.
        expect(pvPaidRequests([$zombie]))->toBe(0, $zombie)
            ->and($zombie)->toStartWith('skipped:claim_lost')
            ->and($message->transcription_attempts)->toBe(0)
            ->and($message->transcription_status)->toBe(TranscriptionStatus::Processing)
            ->and($message->isTranscriptionClaimedBy($stale))->toBeFalse()
            ->and($message->isTranscriptionClaimedBy($fresh))->toBeTrue();
    } finally {
        pvCleanup($user);
    }
});

/*
|--------------------------------------------------------------------------
| The attempt ceiling
|--------------------------------------------------------------------------
*/

it('with one attempt already unproven, racing sweepers and workers produce at most one more request', function () {
    [$user, , $conversation] = pvSubject();
    $message = pvVoiceNote($user, $conversation);

    try {
        // Attempt 1 leaves an unproven outcome: the provider may have processed
        // and charged for it, and we cannot tell.
        $first = pvOutcomes([pvRun(['transcribe', (string) $message->id, '--outcome=unknown'])])[0];

        $message->refresh();
        expect($first)->toStartWith('unknown:')
            ->and($message->transcription_attempts)->toBe(1)
            // NOT failed. An unknown outcome is never a confirmed failure.
            ->and($message->transcription_status)->toBe(TranscriptionStatus::Pending)
            ->and($message->transcription_failure_reason)->toBe(TranscriptionFailureReason::TranscriptionUnknown)
            // And no ledger row was invented for it.
            ->and(DB::table('usage_events')->where('correlation_id', 'message:'.$message->id)->count())->toBe(0);

        // Now let sweepers and workers race for the one remaining attempt.
        pvOutcomes(array_map(fn () => pvRun(['sweep']), range(1, 3)));

        $second = pvOutcomes(array_map(
            fn () => pvRun(['transcribe', (string) $message->id, '--outcome=unknown', '--sleep=50000']),
            range(1, 5),
        ));

        $message->refresh();
        $report = implode(' | ', $second);

        // The ceiling holds: the ONE permitted retry, and never a third.
        expect(pvPaidRequests($second))->toBe(1, $report)
            ->and($message->transcription_attempts)->toBe(2, $report)
            ->and($message->transcription_attempts)->toBeLessThanOrEqual((int) config('voice.max_attempts'))
            // The row is closed and the truth is still not asserted.
            ->and($message->transcription_status)->toBe(TranscriptionStatus::Failed, $report)
            ->and($message->transcription_failure_reason)->toBe(TranscriptionFailureReason::TranscriptionUnknown)
            ->and($message->text_content)->toBeNull();
    } finally {
        pvCleanup($user);
    }
});

it('never spends a third physical request however many workers keep arriving', function () {
    [$user, , $conversation] = pvSubject();
    $message = pvVoiceNote($user, $conversation);

    try {
        $rounds = [];

        for ($round = 0; $round < 3; $round++) {
            pvOutcomes(array_map(fn () => pvRun(['sweep']), range(1, 2)));

            $rounds[] = pvOutcomes(array_map(
                fn () => pvRun(['transcribe', (string) $message->id, '--outcome=unknown', '--sleep=30000']),
                range(1, 4),
            ));
        }

        $message->refresh();
        $paid = array_sum(array_map('pvPaidRequests', $rounds));

        expect($paid)->toBe(2, 'paid requests across 12 workers in 3 rounds')
            ->and($message->transcription_attempts)->toBe(2)
            ->and($message->transcription_status)->toBe(TranscriptionStatus::Failed);
    } finally {
        pvCleanup($user);
    }
});

/*
|--------------------------------------------------------------------------
| Replay
|--------------------------------------------------------------------------
*/

it('never calls the provider again once a transcript is persisted, however many workers try', function () {
    [$user, , $conversation] = pvSubject();
    $message = pvVoiceNote($user, $conversation);

    try {
        $first = pvOutcomes([pvRun(['transcribe', (string) $message->id])])[0];
        expect($first)->toStartWith('transcribed:');

        $message->refresh();
        $transcript = $message->text_content;

        $again = pvOutcomes(array_map(
            fn () => pvRun(['transcribe', (string) $message->id]),
            range(1, 6),
        ));

        $message->refresh();
        $report = implode(' | ', $again);

        // The persisted transcript is the replay guard — not a queue lock,
        // which expires, and not luck.
        expect(pvPaidRequests($again))->toBe(0, $report)
            ->and($message->transcription_attempts)->toBe(1, $report)
            ->and($message->text_content)->toBe($transcript)
            ->and($message->transcription_status)->toBe(TranscriptionStatus::Transcribed)
            ->and(DB::table('usage_events')->where('correlation_id', 'message:'.$message->id)->count())->toBe(2);
    } finally {
        pvCleanup($user);
    }
});

it('a successful settlement racing a retry cannot produce a second transcript', function () {
    [$user, , $conversation] = pvSubject();
    $message = pvVoiceNote($user, $conversation);

    try {
        // Two claims exist at once: an aged one and the takeover. Both workers
        // then run, and both may believe they have work to do.
        $stale = explode(':', pvOutcomes([pvRun(['claim', (string) $message->id])])[0])[1];

        DB::table('messages')->where('id', $message->id)
            ->update(['transcription_claimed_at' => CarbonImmutable::now()->subSeconds(3600)]);

        $fresh = explode(':', pvOutcomes([pvRun(['claim', (string) $message->id])])[0])[1];

        $outcomes = pvOutcomes([
            pvRun(['dispatch', (string) $message->id, $fresh]),
            pvRun(['dispatch', (string) $message->id, $stale]),
        ]);

        $message->refresh();
        $report = implode(' | ', $outcomes);

        // One transcript, produced once and paid for once. The superseded
        // worker never gets to overwrite the answer the subscriber was given.
        expect($message->transcription_status)->toBe(TranscriptionStatus::Transcribed, $report)
            ->and($message->transcription_attempts)->toBe(1, $report)
            ->and(pvPaidRequests($outcomes))->toBe(1, $report)
            ->and(DB::table('messages')->where('id', $message->id)->count())->toBe(1)
            ->and(DB::table('usage_events')->where('correlation_id', 'message:'.$message->id)->count())->toBe(2);
    } finally {
        pvCleanup($user);
    }
});
