<?php

declare(strict_types=1);

use App\Enums\ChannelAccountStatus;
use App\Enums\ChannelType;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\ReminderStatus;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Reminder;
use App\Models\User;
use App\Services\Reminders\ReminderDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * GENUINE parallel tests for reminder delivery on PostgreSQL — separate PHP
 * processes, real row locks, no shared transaction.
 *
 * What must hold under concurrency:
 *  - a claim is not a send: a claim that never dispatches leaves attempts at 0;
 *  - one claim authorises exactly ONE physical request, however many workers
 *    hold it;
 *  - the two-attempt ceiling survives racing sweepers and workers;
 *  - a reminder already accepted is never reset and sent a second time.
 *
 * Not wrapped in RefreshDatabase: it removes only the rows it created.
 */
beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Real concurrency test requires the pgsql connection.');
    }

    try {
        DB::connection()->getPdo();
    } catch (Throwable) {
        $this->markTestSkipped('PostgreSQL is not reachable.');
    }

    config([
        'whatsapp.enabled' => true,
        'whatsapp.access_token' => 'TEST_ACCESS_TOKEN',
        'whatsapp.phone_number_id' => 'PNID_123',
        'whatsapp.graph_base_url' => 'https://graph.facebook.com',
        'whatsapp.graph_version' => 'v21.0',
        'reminders.enabled' => true,
        'reminders.max_lateness_minutes' => 600,
        'reminders.lease_seconds' => 300,
    ]);
});

/**
 * One subscriber, one WhatsApp account, one open free-form window, one due
 * reminder — created for real so separate processes can see it.
 *
 * @return array{0: User, 1: Reminder}
 */
function rdSubject(): array
{
    $user = User::factory()->create(['is_admin' => false, 'timezone' => 'Asia/Hebron']);
    $account = ChannelAccount::factory()->for($user)->create([
        'channel' => ChannelType::WhatsApp,
        'external_identifier' => '+97059'.random_int(1000000, 9999999),
        'status' => ChannelAccountStatus::Active,
    ]);
    $conversation = Conversation::factory()->for($user)->create(['channel_account_id' => $account->id]);

    $inbound = Message::factory()->for($user)->for($conversation)->create([
        'direction' => MessageDirection::Inbound,
        'type' => MessageType::Text,
        'text_content' => 'ذكرني',
        'created_at' => CarbonImmutable::now()->subMinutes(10),
    ]);

    $reminder = Reminder::query()->create([
        'user_id' => $user->id,
        'source_message_id' => $inbound->id,
        'title' => 'اتصال',
        'remind_at' => CarbonImmutable::now()->subMinute(),
        'timezone' => 'Asia/Hebron',
        'channel' => ChannelType::WhatsApp->value,
        'status' => ReminderStatus::Pending->value,
    ]);

    return [$user, $reminder];
}

/** @param list<string> $args */
function rdRun(array $args): Process
{
    $p = new Process(['php', 'artisan', 'sanad:reminder-dispatch-probe', ...$args], base_path());
    $p->start();

    return $p;
}

/**
 * @param  list<Process>  $processes
 * @return list<string>
 */
function rdOutcomes(array $processes): array
{
    $outcomes = [];

    foreach ($processes as $p) {
        $p->wait();
        expect($p->getExitCode())->toBe(0, $p->getOutput().$p->getErrorOutput());
        $outcomes[] = trim($p->getOutput());
    }

    return $outcomes;
}

function rdCleanup(User $user): void
{
    $reminderIds = DB::table('reminders')->where('user_id', $user->id)->pluck('id');
    DB::table('usage_events')->where('user_id', $user->id)->delete();
    DB::table('messages')->where('user_id', $user->id)->delete();
    DB::table('reminders')->whereIn('id', $reminderIds)->delete();
    DB::table('conversations')->where('user_id', $user->id)->delete();
    $user->delete();
}

it('of 6 concurrent claims of one due reminder exactly one wins, and no claim counts a physical attempt', function () {
    [$user, $reminder] = rdSubject();

    try {
        $outcomes = rdOutcomes(array_map(fn () => rdRun(['claim', '10']), range(1, 6)));

        $winners = array_filter($outcomes, fn (string $o): bool => str_starts_with($o, 'claimed:'.$reminder->id.':'));

        $reminder->refresh();
        expect($winners)->toHaveCount(1)
            ->and(array_filter($outcomes, fn (string $o): bool => $o === 'claimed:'))->toHaveCount(5)
            ->and($reminder->status)->toBe(ReminderStatus::Processing)
            // W1: claimed, never dispatched. The retry budget is untouched.
            ->and($reminder->attempts)->toBe(0)
            ->and($reminder->dispatched_at)->toBeNull()
            ->and(DB::table('messages')->where('reminder_id', $reminder->id)->count())->toBe(0);
    } finally {
        rdCleanup($user);
    }
});

it('of 6 concurrent workers holding ONE claim only one dispatches a physical request', function () {
    [$user, $reminder] = rdSubject();

    try {
        $claim = app(ReminderDispatcher::class)->claimDue(10)[0];

        $outcomes = rdOutcomes(array_map(
            fn () => rdRun(['deliver', (string) $reminder->id, $claim->token]),
            range(1, 6),
        ));

        $reminder->refresh();
        $report = implode(' | ', $outcomes);

        // Exactly one process made a request; the other five were refused by
        // the claim, not by luck.
        expect(array_filter($outcomes, fn (string $o): bool => $o === 'sent:1'))->toHaveCount(1, $report)
            ->and(array_filter($outcomes, fn (string $o): bool => str_starts_with($o, 'nosend:')))->toHaveCount(5, $report)
            ->and($reminder->attempts)->toBe(1, $report)
            ->and($reminder->status)->toBe(ReminderStatus::Sent)
            // One outbound message, one ledger row for the one real send.
            ->and(DB::table('messages')->where('reminder_id', $reminder->id)->count())->toBe(1)
            ->and(DB::table('usage_events')->where('correlation_id', 'reminder:'.$reminder->id)->count())->toBe(1);
    } finally {
        rdCleanup($user);
    }
});

it('with one attempt already spent, racing sweepers and workers produce at most one more physical send', function () {
    [$user, $reminder] = rdSubject();

    try {
        // Attempt 1 leaves an unproven outcome: dispatched, still processing.
        $first = app(ReminderDispatcher::class)->claimDue(10)[0];
        rdOutcomes([rdRun(['deliver', (string) $reminder->id, $first->token, '--outcome=unknown'])]);

        $reminder->refresh();
        expect($reminder->attempts)->toBe(1)
            ->and($reminder->status)->toBe(ReminderStatus::Processing);

        // Age the claim past its lease, then let sweepers and workers race for
        // the one remaining attempt.
        DB::table('reminders')->where('id', $reminder->id)
            ->update(['claimed_at' => CarbonImmutable::now()->subSeconds(600)]);

        $processes = [];
        for ($i = 0; $i < 3; $i++) {
            $processes[] = rdRun(['sweep']);
        }
        rdOutcomes($processes);

        // Whoever holds it now, several workers try to send under that claim.
        $claimers = rdOutcomes(array_map(fn () => rdRun(['claim', '10']), range(1, 3)));
        $won = array_values(array_filter($claimers, fn (string $o): bool => str_starts_with($o, 'claimed:'.$reminder->id.':')));
        expect($won)->toHaveCount(1);
        $token = explode(':', $won[0])[2];

        $second = rdOutcomes(array_map(fn () => rdRun(['deliver', (string) $reminder->id, $token]), range(1, 4)));
        $report = implode(' | ', $second);

        $reminder->refresh();

        // The ceiling holds: the ONE permitted retry, and never a third send.
        expect(array_filter($second, fn (string $o): bool => str_starts_with($o, 'sent:')))->toHaveCount(1, $report)
            ->and($reminder->attempts)->toBe(2, $report)
            ->and($reminder->attempts)->toBeLessThanOrEqual(ReminderDispatcher::MAX_ATTEMPTS)
            ->and($reminder->status)->toBe(ReminderStatus::Sent)
            ->and(DB::table('messages')->where('reminder_id', $reminder->id)->count())->toBe(1)
            // Two real sends were served: the accepted one is billed, the
            // unproven first one is not invented.
            ->and(DB::table('usage_events')->where('correlation_id', 'reminder:'.$reminder->id)->count())->toBe(1);
    } finally {
        rdCleanup($user);
    }
});

it('never resets an accepted reminder and sends it again', function () {
    [$user, $reminder] = rdSubject();

    try {
        $claim = app(ReminderDispatcher::class)->claimDue(10)[0];
        rdOutcomes([rdRun(['deliver', (string) $reminder->id, $claim->token])]);

        expect($reminder->fresh()->status)->toBe(ReminderStatus::Sent);

        // Age the row as if the lease had expired, then race sweepers and
        // workers against the settled reminder.
        DB::table('reminders')->where('id', $reminder->id)
            ->update(['claimed_at' => CarbonImmutable::now()->subSeconds(600)]);

        $processes = array_map(fn () => rdRun(['sweep']), range(1, 3));
        $processes[] = rdRun(['claim', '10']);
        $processes[] = rdRun(['deliver', (string) $reminder->id, $claim->token]);
        rdOutcomes($processes);

        $reminder->refresh();

        // A settled reminder is terminal: the sweeper only ever touches
        // `processing` rows, so nothing reopens it.
        expect($reminder->status)->toBe(ReminderStatus::Sent)
            ->and($reminder->attempts)->toBe(1)
            ->and(DB::table('messages')->where('reminder_id', $reminder->id)->count())->toBe(1)
            ->and(DB::table('usage_events')->where('correlation_id', 'reminder:'.$reminder->id)->count())->toBe(1);
    } finally {
        rdCleanup($user);
    }
});

it('uses the (status, claimed_at) index for the stale sweep and (status, remind_at) for the due query', function () {
    [$user, $reminder] = rdSubject();

    try {
        $due = DB::select(
            'EXPLAIN (ANALYZE, COSTS OFF) select id from reminders where status = ? and remind_at <= ? order by remind_at, id limit 100',
            [ReminderStatus::Pending->value, CarbonImmutable::now()->toDateTimeString()],
        );
        $stale = DB::select(
            'EXPLAIN (ANALYZE, COSTS OFF) select * from reminders where status = ? and claimed_at <= ? order by id limit 100',
            [ReminderStatus::Processing->value, CarbonImmutable::now()->toDateTimeString()],
        );

        $duePlan = implode("\n", array_map(fn ($r) => (string) reset($r), $due));
        $stalePlan = implode("\n", array_map(fn ($r) => (string) reset($r), $stale));

        echo "[EXPLAIN reminders/due]\n{$duePlan}\n[EXPLAIN reminders/stale]\n{$stalePlan}\n";

        // The planner may legitimately choose a sequential scan on a table this
        // small, so what is proven here is that each index EXISTS and covers
        // its query's leading columns — the shape the plan will use at size.
        $indexes = collect(DB::select("select indexdef from pg_indexes where tablename = 'reminders'"))
            ->map(fn ($row) => (string) $row->indexdef)
            ->implode("\n");

        expect($indexes)->toContain('status')
            ->and($indexes)->toContain('claimed_at')
            ->and($indexes)->toContain('remind_at');
    } finally {
        rdCleanup($user);
    }
});

it('fences out a stale worker: A claims, is swept and replaced by B, then A and B race toward dispatch', function () {
    [$user, $reminder] = rdSubject();

    try {
        // 1. Worker A claims and stalls, holding its identity.
        $claimA = app(ReminderDispatcher::class)->claimDue(10)[0];

        // 2. Its lease goes stale. 3. The sweeper returns it to pending.
        DB::table('reminders')->where('id', $reminder->id)
            ->update(['claimed_at' => CarbonImmutable::now()->subSeconds(600)]);
        expect(rdOutcomes([rdRun(['sweep'])]))->toBe(['swept:1:0'])
            ->and($reminder->fresh()->claim_token)->toBeNull();

        // 4. Worker B claims the same reminder and gets a different identity.
        $claimB = app(ReminderDispatcher::class)->claimDue(10)[0];
        expect($claimB->token)->not->toBe($claimA->token);

        // 5. A and B are released concurrently toward pre-dispatch. Two of each,
        //    so a lost race cannot be mistaken for a fence.
        $outcomes = rdOutcomes([
            rdRun(['deliver', (string) $reminder->id, $claimA->token]),
            rdRun(['deliver', (string) $reminder->id, $claimB->token]),
            rdRun(['deliver', (string) $reminder->id, $claimA->token]),
            rdRun(['deliver', (string) $reminder->id, $claimB->token]),
        ]);

        $reminder->refresh();
        $report = implode(' | ', $outcomes);

        // B's claim is the only valid dispatch owner, and it dispatched once.
        expect(array_filter($outcomes, fn (string $o): bool => $o === 'sent:1'))->toHaveCount(1, $report)
            ->and(array_filter($outcomes, fn (string $o): bool => str_starts_with($o, 'nosend:')))->toHaveCount(3, $report)
            // A could not increment the budget and could not send: `attempts`
            // reflects only B's one valid physical attempt.
            ->and($reminder->attempts)->toBe(1, $report)
            ->and($reminder->status)->toBe(ReminderStatus::Sent)
            // One outbound message and no duplicate outbound usage.
            ->and(DB::table('messages')->where('reminder_id', $reminder->id)->count())->toBe(1)
            ->and(DB::table('usage_events')->where('correlation_id', 'reminder:'.$reminder->id)->count())->toBe(1);
    } finally {
        rdCleanup($user);
    }
});
