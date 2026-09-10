<?php

declare(strict_types=1);

use App\Enums\ChannelAccountStatus;
use App\Enums\ChannelType;
use App\Enums\FollowUpBlockReason;
use App\Enums\FollowUpStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\PlanFeature;
use App\Enums\ReminderFailureReason;
use App\Enums\ReminderStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TaskStatus;
use App\Enums\ToolCapability;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\FollowUp;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Reminder;
use App\Models\Subscription;
use App\Models\Task;
use App\Models\User;
use App\Services\Reminders\ReminderDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * GENUINE parallel tests for follow-up loops on PostgreSQL — separate PHP
 * processes, real row locks, no shared transaction.
 *
 * Follow-up adds a class of race the delivery and recurrence tests do not cover:
 * several processes deciding whether Sanad may SPEAK AGAIN. What must hold:
 *
 *  - replaying one tool call produces ONE loop, not one per process;
 *  - concurrent materialisers produce ONE ask for `(follow_up_id, ask_index)`;
 *  - only ever ONE outstanding ask, however many runs arrive;
 *  - an answer racing the next ask leaves no live ask behind, and neither does a
 *    cancellation — the version fence decides, not timing;
 *  - a stale worker cannot mutate a loop that has already closed;
 *  - each ask keeps its own physical attempt budget;
 *  - a template-blocked loop spends NO logical ask budget and produces no stream
 *    of failed asks;
 *  - an ambiguous reply resolves nothing, and a correlated one resolves once;
 *  - completing a genuinely linked task closes the loop exactly once.
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
        'follow_ups.enabled' => true,
        'follow_ups.max_open_per_subscriber' => 5,
        'follow_ups.max_asks_per_follow_up' => 3,
        'follow_ups.min_ask_interval_hours' => 24,
        'follow_ups.answer_window_hours' => 72,
        'follow_ups.whatsapp.template.ready' => true,
        'follow_ups.whatsapp.template.name' => 'sanad_follow_up_v1',
        'follow_ups.whatsapp.template.language' => 'ar',
        'reminders.enabled' => true,
        'whatsapp.enabled' => true,
        'whatsapp.access_token' => 'TEST_ACCESS_TOKEN',
        'whatsapp.phone_number_id' => 'PNID_123',
    ]);
});

/**
 * One entitled subscriber, a WhatsApp account, an open conversation and an inbound
 * message that carries BOTH authorities (explicit intent and a definite time) —
 * committed, so separate processes can see it.
 *
 * @return array{0: User, 1: Conversation, 2: Message}
 */
function pfuSubject(): array
{
    $plan = Plan::create([
        'name' => 'Follow-Up Probe',
        'slug' => 'follow-up-probe-'.bin2hex(random_bytes(5)),
        'price' => 0,
        'currency' => 'ILS',
        'billing_period' => 'monthly',
        'trial_days' => 0,
        'limits' => ['ai_reply' => ['daily' => 1000, 'monthly' => 10000, 'weight' => 1]],
        'features' => [PlanFeature::FollowUp->value => true, PlanFeature::Tools->value => true],
        'is_active' => true,
        'is_default' => false,
        'sort_order' => 0,
    ]);

    $user = User::factory()->create([
        'is_admin' => false,
        'timezone' => 'Asia/Hebron',
        'locale' => 'ar',
        'email' => 'fu-probe-'.bin2hex(random_bytes(6)).'@example.test',
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

    $message = Message::factory()->for($user)->for($conversation)->create([
        'direction' => MessageDirection::Inbound,
        'type' => MessageType::Text,
        'text_content' => 'تابع معي بكرا الساعة ٩ إذا دفعت الفاتورة',
        'created_at' => CarbonImmutable::now()->subMinutes(5),
    ]);

    return [$user, $conversation, $message];
}

function pfuReply(User $user, Conversation $conversation, string $text, ?CarbonImmutable $at = null): Message
{
    return Message::factory()->for($user)->for($conversation)->create([
        'direction' => MessageDirection::Inbound,
        'type' => MessageType::Text,
        'text_content' => $text,
        'created_at' => $at ?? CarbonImmutable::now(),
    ]);
}

/** A loop created directly, committed, with its first ask already due. */
function pfuLoop(User $user, Message $message, array $attributes = []): FollowUp
{
    return FollowUp::query()->create(array_merge([
        'user_id' => $user->id,
        'source_message_id' => $message->id,
        'question' => 'دفعت فاتورة الكهربا؟',
        'channel' => ChannelType::WhatsApp->value,
        'timezone' => 'Asia/Hebron',
        'status' => FollowUpStatus::Open->value,
        'max_asks' => 3,
        'next_ask_at' => CarbonImmutable::now('UTC')->subMinute(),
    ], $attributes));
}

/** @param list<string> $args */
function pfuRun(array $args): Process
{
    $p = new Process(['php', 'artisan', 'sanad:follow-up-probe', ...$args], base_path());
    $p->start();

    return $p;
}

/**
 * @param  list<Process>  $processes
 * @return list<string>
 */
function pfuOutcomes(array $processes): array
{
    $outcomes = [];

    foreach ($processes as $p) {
        $p->wait();
        expect($p->getExitCode())->toBe(0, $p->getOutput().$p->getErrorOutput());
        $outcomes[] = trim($p->getOutput());
    }

    return $outcomes;
}

/** Mark the loop's latest ask as having genuinely left the platform. */
function pfuSend(FollowUp $followUp, ?CarbonImmutable $at = null): Reminder
{
    $at ??= CarbonImmutable::now()->subHours(30);

    /** @var Reminder $ask */
    $ask = Reminder::query()
        ->where('follow_up_id', $followUp->getKey())
        ->orderByDesc('ask_index')
        ->firstOrFail();

    $ask->forceFill([
        'attempts' => 1,
        'dispatched_at' => $at,
        'sent_at' => $at,
        'status' => ReminderStatus::Sent->value,
    ])->save();

    return $ask;
}

function pfuCleanup(User $user): void
{
    $reminderIds = DB::table('reminders')->where('user_id', $user->id)->pluck('id');
    $invocationIds = DB::table('tool_invocations')->where('subscriber_id', $user->id)->pluck('id');

    DB::table('usage_events')->where('user_id', $user->id)->delete();
    DB::table('tool_invocation_events')->whereIn('tool_invocation_id', $invocationIds)->delete();
    /*
     * The audit rows the tool path wrote point at the INVOCATION, not at the
     * subscriber — so they have to be collected before the invocations go, or they
     * survive this test and break the next one that expects an empty audit table.
     */
    DB::table('audit_logs')
        ->where('subject_type', 'App\\Models\\ToolInvocation')
        ->whereIn('subject_id', $invocationIds)
        ->delete();
    DB::table('audit_logs')->where('user_id', $user->id)->delete();
    DB::table('tool_invocations')->where('subscriber_id', $user->id)->delete();
    DB::table('tool_consents')->where('subscriber_id', $user->id)->delete();
    DB::table('messages')->where('user_id', $user->id)->delete();
    DB::table('reminders')->whereIn('id', $reminderIds)->delete();
    DB::table('follow_ups')->where('user_id', $user->id)->delete();
    DB::table('tasks')->where('user_id', $user->id)->delete();
    DB::table('conversations')->where('user_id', $user->id)->delete();
    DB::table('channel_accounts')->where('user_id', $user->id)->delete();
    $planIds = DB::table('subscriptions')->where('subscriber_id', $user->id)->pluck('plan_id');
    DB::table('subscriptions')->where('subscriber_id', $user->id)->delete();
    $user->delete();
    DB::table('plans')->whereIn('id', $planIds)->delete();
}

/*
|--------------------------------------------------------------------------
| Opening a loop
|--------------------------------------------------------------------------
*/

it('of 6 concurrent replays of one tool call, exactly one loop exists', function () {
    [$user, , $message] = pfuSubject();

    try {
        f3Consent($user, ToolCapability::FollowUpsWrite);

        $outcomes = pfuOutcomes(array_map(fn () => pfuRun(['tool', (string) $message->id, '--sleep=40000']), range(1, 6)));
        $report = implode(' | ', $outcomes);

        // The invocation slot is `(message, tool, canonical input)` and it is
        // UNIQUE, so one process executes and the rest replay — which is why the
        // follow-up domain needs no second, weaker idempotency rule of its own.
        expect(DB::table('follow_ups')->where('user_id', $user->id)->count())->toBe(1, $report)
            ->and(DB::table('tool_invocations')->where('subscriber_id', $user->id)->count())->toBe(1, $report)
            ->and(array_filter($outcomes, static fn (string $o): bool => str_starts_with($o, 'claimed:')))->toHaveCount(1, $report);

        $row = DB::table('follow_ups')->where('user_id', $user->id)->first();

        expect($row->status)->toBe(FollowUpStatus::Open->value)
            ->and((int) $row->max_asks)->toBe(3)
            // Opening a loop creates NO ask: materialisation does that later.
            ->and(DB::table('reminders')->where('follow_up_id', $row->id)->count())->toBe(0);
    } finally {
        pfuCleanup($user);
    }
});

it('of 12 concurrent creations at a cap of 5, exactly 5 live loops exist', function () {
    [$user, , $message] = pfuSubject();

    try {
        $outcomes = pfuOutcomes(array_map(
            fn () => pfuRun(['create', (string) $user->id, (string) $message->id, '--sleep=30000']),
            range(1, 12),
        ));
        $report = implode(' | ', $outcomes);

        // The cap is enforced on the SUBSCRIBER'S row, so two creations one below
        // it cannot both count room: `FOR UPDATE` can lock the user, and cannot
        // lock a loop that does not exist yet.
        expect(DB::table('follow_ups')->where('user_id', $user->id)->count())->toBe(5, $report)
            ->and(array_filter($outcomes, static fn (string $o): bool => str_starts_with($o, 'created:')))->toHaveCount(5, $report)
            ->and(array_filter($outcomes, static fn (string $o): bool => $o === 'refused:ToolDomainException'))->toHaveCount(7, $report);
    } finally {
        pfuCleanup($user);
    }
});

/*
|--------------------------------------------------------------------------
| Asking
|--------------------------------------------------------------------------
*/

it('of 6 concurrent materialisers, exactly one ask exists for the loop', function () {
    [$user, , $message] = pfuSubject();
    $followUp = pfuLoop($user, $message);

    try {
        $outcomes = pfuOutcomes(array_map(
            fn () => pfuRun(['advance', (string) $followUp->id, '--sleep=40000']),
            range(1, 6),
        ));
        $report = implode(' | ', $outcomes);

        $asks = DB::table('reminders')->where('follow_up_id', $followUp->id)->get();

        // `UNIQUE (follow_up_id, ask_index)` is the arbiter, so a concurrent run is
        // a no-op and never a second unsolicited message.
        expect($asks)->toHaveCount(1, $report)
            ->and((int) $asks[0]->ask_index)->toBe(1)
            ->and(array_filter($outcomes, static fn (string $o): bool => $o === 'created'))->toHaveCount(1, $report)
            ->and(array_filter($outcomes, static fn (string $o): bool => str_starts_with($o, 'threw:')))->toHaveCount(0, $report);
    } finally {
        pfuCleanup($user);
    }
});

it('never holds two outstanding asks, however many runs arrive across rounds', function () {
    [$user, , $message] = pfuSubject();
    $followUp = pfuLoop($user, $message);

    try {
        // Round 1: create the ask.
        pfuOutcomes(array_map(fn () => pfuRun(['advance', (string) $followUp->id, '--sleep=30000']), range(1, 4)));

        expect(DB::table('reminders')->where('follow_up_id', $followUp->id)->count())->toBe(1);

        // Round 2: the ask has left the platform and the interval has passed, so
        // exactly one more becomes eligible — and four processes race for it.
        pfuSend($followUp->fresh());
        pfuOutcomes(array_map(fn () => pfuRun(['advance', (string) $followUp->id]), range(1, 2)));
        $outcomes = pfuOutcomes(array_map(fn () => pfuRun(['advance', (string) $followUp->id, '--sleep=30000']), range(1, 4)));
        $report = implode(' | ', $outcomes);

        $asks = DB::table('reminders')->where('follow_up_id', $followUp->id)->orderBy('ask_index')->get();

        expect($asks)->toHaveCount(2, $report)
            ->and(array_map(static fn ($a): int => (int) $a->ask_index, $asks->all()))->toBe([1, 2])
            // And at most ONE is outstanding at any moment.
            ->and(DB::table('reminders')
                ->where('follow_up_id', $followUp->id)
                ->whereIn('status', [ReminderStatus::Pending->value, ReminderStatus::Processing->value])
                ->count())->toBe(1, $report);
    } finally {
        pfuCleanup($user);
    }
});

it('stops at the ask budget under racing runs, and retires the loop silently', function () {
    [$user, , $message] = pfuSubject();
    $followUp = pfuLoop($user, $message, ['max_asks' => 2]);

    try {
        foreach (range(1, 2) as $round) {
            pfuOutcomes(array_map(fn () => pfuRun(['advance', (string) $followUp->id, '--sleep=20000']), range(1, 3)));
            pfuSend($followUp->fresh());
            pfuOutcomes([pfuRun(['advance', (string) $followUp->id])]);
        }

        $outcomes = pfuOutcomes(array_map(fn () => pfuRun(['advance', (string) $followUp->id, '--sleep=20000']), range(1, 5)));
        $report = implode(' | ', $outcomes);

        $state = pfuOutcomes([pfuRun(['state', (string) $followUp->id])])[0];

        // Two asks, both spent, and a terminal state nobody escalates out of.
        expect($state)->toBe('abandoned:2:2', $report)
            ->and(DB::table('reminders')->where('follow_up_id', $followUp->id)->count())->toBe(2, $report);
    } finally {
        pfuCleanup($user);
    }
});

/*
|--------------------------------------------------------------------------
| The version fence: answers and cancellations versus materialisation
|--------------------------------------------------------------------------
*/

it('an answer committing mid-flight leaves no live ask behind', function () {
    [$user, $conversation, $message] = pfuSubject();
    $followUp = pfuLoop($user, $message);

    try {
        // Ask 1 is out, the interval has passed, so another ask is eligible.
        pfuOutcomes([pfuRun(['advance', (string) $followUp->id])]);
        pfuSend($followUp->fresh());
        pfuOutcomes([pfuRun(['advance', (string) $followUp->id])]);

        $reply = pfuReply($user, $conversation, 'اه دفعتها');

        // The race: four materialisers and one confirmation, all in flight.
        $processes = array_map(fn () => pfuRun(['advance', (string) $followUp->id, '--sleep=40000']), range(1, 4));
        $processes[] = pfuRun(['resolve', (string) $followUp->id, (string) $reply->id, 'confirmed', '--sleep=40000']);

        $outcomes = pfuOutcomes($processes);
        $report = implode(' | ', $outcomes);

        $fresh = DB::table('follow_ups')->where('id', $followUp->id)->first();

        // Whoever won the lock, the end state is the one the subscriber asked for:
        // the loop is closed and NOTHING of it is still queued to fire.
        expect($fresh->status)->toBe(FollowUpStatus::ResolvedConfirmed->value, $report)
            ->and($fresh->resolved_at)->not->toBeNull()
            ->and($fresh->next_ask_at)->toBeNull()
            ->and(DB::table('reminders')
                ->where('follow_up_id', $followUp->id)
                ->whereIn('status', [ReminderStatus::Pending->value, ReminderStatus::Processing->value])
                ->count())->toBe(0, $report);

        // And no later run can revive it.
        $after = pfuOutcomes(array_map(fn () => pfuRun(['advance', (string) $followUp->id]), range(1, 4)));

        expect(array_unique($after))->toBe(['inert'], implode(' | ', $after));
    } finally {
        pfuCleanup($user);
    }
});

it('a cancellation committing mid-flight leaves no live ask behind', function () {
    [$user, , $message] = pfuSubject();
    $followUp = pfuLoop($user, $message);

    try {
        $processes = array_map(fn () => pfuRun(['advance', (string) $followUp->id, '--sleep=40000']), range(1, 4));
        $processes[] = pfuRun(['cancel', (string) $followUp->id, (string) $user->id, '--sleep=40000']);

        $outcomes = pfuOutcomes($processes);
        $report = implode(' | ', $outcomes);

        $fresh = DB::table('follow_ups')->where('id', $followUp->id)->first();

        expect($fresh->status)->toBe(FollowUpStatus::Cancelled->value, $report)
            ->and($fresh->terminated_at)->not->toBeNull()
            ->and(DB::table('reminders')
                ->where('follow_up_id', $followUp->id)
                ->whereIn('status', [ReminderStatus::Pending->value, ReminderStatus::Processing->value])
                ->count())->toBe(0, $report);

        $after = pfuOutcomes(array_map(fn () => pfuRun(['advance', (string) $followUp->id]), range(1, 6)));

        expect(array_unique($after))->toBe(['inert'], implode(' | ', $after));
    } finally {
        pfuCleanup($user);
    }
});

it('of 5 concurrent cancellations exactly one terminates the loop, and the rest say so', function () {
    [$user, , $message] = pfuSubject();
    $followUp = pfuLoop($user, $message);

    try {
        pfuOutcomes([pfuRun(['advance', (string) $followUp->id])]);

        $outcomes = pfuOutcomes(array_map(
            fn () => pfuRun(['cancel', (string) $followUp->id, (string) $user->id, '--sleep=30000']),
            range(1, 5),
        ));
        $report = implode(' | ', $outcomes);

        $terminators = array_filter($outcomes, static fn (string $o): bool => str_ends_with($o, ':1'));

        expect($terminators)->toHaveCount(1, $report)
            // And the ask was cancelled once, not five times over.
            ->and(array_sum(array_map(
                static fn (string $o): int => (int) (explode(':', $o)[1] ?? 0),
                $outcomes,
            )))->toBe(1, $report);
    } finally {
        pfuCleanup($user);
    }
});

it('a stale worker cannot mutate a loop that has already closed', function () {
    [$user, $conversation, $message] = pfuSubject();
    $followUp = pfuLoop($user, $message);

    try {
        pfuOutcomes([pfuRun(['advance', (string) $followUp->id])]);
        pfuSend($followUp->fresh(), CarbonImmutable::now()->subHours(2));
        pfuOutcomes([pfuRun(['advance', (string) $followUp->id])]);

        $reply = pfuReply($user, $conversation, 'اه خلصت');
        pfuOutcomes([pfuRun(['resolve', (string) $followUp->id, (string) $reply->id, 'confirmed'])]);

        $closedAt = DB::table('follow_ups')->where('id', $followUp->id)->value('resolved_at');
        $version = DB::table('follow_ups')->where('id', $followUp->id)->value('version');

        // A worker that read this loop as live long ago now wakes up and tries
        // everything it knows.
        $late = pfuOutcomes([
            pfuRun(['advance', (string) $followUp->id]),
            pfuRun(['resolve', (string) $followUp->id, (string) $reply->id, 'not_yet']),
            pfuRun(['resolve', (string) $followUp->id, (string) $reply->id, 'confirmed']),
        ]);
        $report = implode(' | ', $late);

        $fresh = DB::table('follow_ups')->where('id', $followUp->id)->first();

        expect($fresh->status)->toBe(FollowUpStatus::ResolvedConfirmed->value, $report)
            ->and($fresh->resolved_at)->toBe($closedAt, $report)
            ->and((int) $fresh->version)->toBe((int) $version, $report)
            // Each late resolve is honest rather than an error: the loop is closed
            // and this call was not the one that closed it.
            ->and(array_filter($late, static fn (string $o): bool => str_starts_with($o, 'resolved:0:')))->toHaveCount(2, $report)
            ->and(DB::table('reminders')->where('follow_up_id', $followUp->id)->count())->toBe(1, $report);
    } finally {
        pfuCleanup($user);
    }
});

/*
|--------------------------------------------------------------------------
| Correlation under concurrency
|--------------------------------------------------------------------------
*/

it('an ambiguous reply resolves nothing when two loops are awaiting an answer', function () {
    [$user, $conversation, $message] = pfuSubject();
    $first = pfuLoop($user, $message);
    $second = pfuLoop($user, $message, ['question' => 'راجعت الطبيب؟']);

    try {
        foreach ([$first, $second] as $loop) {
            pfuOutcomes([pfuRun(['advance', (string) $loop->id])]);
            pfuSend($loop->fresh(), CarbonImmutable::now()->subHours(2));
            pfuOutcomes([pfuRun(['advance', (string) $loop->id])]);
        }

        $reply = pfuReply($user, $conversation, 'اه');

        // One bare «آه», two open questions: the domain must not pick.
        $outcomes = pfuOutcomes([
            pfuRun(['resolve', (string) $first->id, (string) $reply->id, 'confirmed', '--sleep=20000']),
            pfuRun(['resolve', (string) $second->id, (string) $reply->id, 'confirmed', '--sleep=20000']),
        ]);
        $report = implode(' | ', $outcomes);

        expect(array_unique($outcomes))->toBe(['refused:ToolDomainException'], $report)
            ->and(DB::table('follow_ups')->whereIn('id', [$first->id, $second->id])
                ->where('status', FollowUpStatus::AwaitingAnswer->value)->count())->toBe(2, $report);
    } finally {
        pfuCleanup($user);
    }
});

it('a correlated confirmation resolves exactly once across racing processes', function () {
    [$user, $conversation, $message] = pfuSubject();
    $followUp = pfuLoop($user, $message);

    try {
        pfuOutcomes([pfuRun(['advance', (string) $followUp->id])]);
        pfuSend($followUp->fresh(), CarbonImmutable::now()->subHours(2));
        pfuOutcomes([pfuRun(['advance', (string) $followUp->id])]);

        $reply = pfuReply($user, $conversation, 'اه دفعتها');

        $outcomes = pfuOutcomes(array_map(
            fn () => pfuRun(['resolve', (string) $followUp->id, (string) $reply->id, 'confirmed', '--sleep=30000']),
            range(1, 5),
        ));
        $report = implode(' | ', $outcomes);

        /*
         * EXACTLY ONE CALL CLOSED IT. A loser lands in one of two honest places,
         * depending on where it was when the winner committed: it reads the loop as
         * already closed (`resolved:0`), or its correlation finds no loop awaiting
         * an answer any more and it refuses. Neither closes the loop a second time,
         * and neither is a silent success — which is the property under test.
         */
        $closed = array_filter($outcomes, static fn (string $o): bool => str_starts_with($o, 'resolved:1:'));
        $didNot = array_filter($outcomes, static fn (string $o): bool => str_starts_with($o, 'resolved:0:') || str_starts_with($o, 'refused:'));

        expect($closed)->toHaveCount(1, $report)
            ->and($didNot)->toHaveCount(4, $report)
            ->and(DB::table('follow_ups')->where('id', $followUp->id)->value('status'))
            ->toBe(FollowUpStatus::ResolvedConfirmed->value);
    } finally {
        pfuCleanup($user);
    }
});

it('a correlated "not yet" does not resolve, and schedules at most one next ask', function () {
    [$user, $conversation, $message] = pfuSubject();
    $followUp = pfuLoop($user, $message);

    try {
        pfuOutcomes([pfuRun(['advance', (string) $followUp->id])]);
        $ask = pfuSend($followUp->fresh(), CarbonImmutable::now()->subHours(2));
        pfuOutcomes([pfuRun(['advance', (string) $followUp->id])]);

        $reply = pfuReply($user, $conversation, 'لسا ما دفعت');

        $outcomes = pfuOutcomes(array_map(
            fn () => pfuRun(['resolve', (string) $followUp->id, (string) $reply->id, 'not_yet', '--sleep=30000']),
            range(1, 4),
        ));
        $report = implode(' | ', $outcomes);

        $fresh = DB::table('follow_ups')->where('id', $followUp->id)->first();

        expect($fresh->status)->toBe(FollowUpStatus::Open->value, $report)
            ->and($fresh->resolved_at)->toBeNull()
            ->and($fresh->next_ask_at)->not->toBeNull();

        // The next ask is the LAST ASK plus the interval, so answering «لسا» is
        // never itself the thing that triggers the next message.
        expect(CarbonImmutable::parse($fresh->next_ask_at)->greaterThan(CarbonImmutable::now()))->toBeTrue($report);

        // And racing materialisers create nothing while that time is in the future.
        $after = pfuOutcomes(array_map(fn () => pfuRun(['advance', (string) $followUp->id]), range(1, 4)));

        expect(DB::table('reminders')->where('follow_up_id', $followUp->id)->count())->toBe(1, implode(' | ', $after));
    } finally {
        pfuCleanup($user);
    }
});

/*
|--------------------------------------------------------------------------
| The template hold
|--------------------------------------------------------------------------
*/

it('holds a loop whose template is missing, spends no budget and creates no failed asks', function () {
    [$user, , $message] = pfuSubject();
    $followUp = pfuLoop($user, $message);

    try {
        // The service window is closed, so an ask would need the approved template.
        DB::table('messages')->where('user_id', $user->id)->update(['created_at' => CarbonImmutable::now()->subDays(5)]);

        // `--template=0` makes the dependency missing in the CHILD processes, which
        // is where the decision is actually made.
        $outcomes = pfuOutcomes(array_map(
            fn () => pfuRun(['advance', (string) $followUp->id, '--template=0', '--sleep=30000']),
            range(1, 6),
        ));
        $report = implode(' | ', $outcomes);

        $state = pfuOutcomes([pfuRun(['state', (string) $followUp->id])])[0];
        $fresh = DB::table('follow_ups')->where('id', $followUp->id)->first();

        // Held, with NO ask created and NO budget spent — a configuration gap can
        // neither drain the ladder nor fill the queue with refusals.
        expect($state)->toBe('blocked:0:0', $report)
            ->and($fresh->blocked_reason)->toBe(FollowUpBlockReason::TemplateUnavailable->value)
            ->and($fresh->next_ask_at)->toBeNull();

        // Twenty more runs change nothing.
        $again = pfuOutcomes(array_map(
            fn () => pfuRun(['advance', (string) $followUp->id, '--template=0']),
            range(1, 10),
        ));

        expect(array_unique($again))->toBe(['inert'], implode(' | ', $again))
            ->and(pfuOutcomes([pfuRun(['state', (string) $followUp->id])])[0])->toBe('blocked:0:0');
    } finally {
        pfuCleanup($user);
    }
});

/*
|--------------------------------------------------------------------------
| Task completion
|--------------------------------------------------------------------------
*/

it('closes the loop exactly once when a genuinely linked task is completed concurrently', function () {
    [$user, , $message] = pfuSubject();

    $task = Task::query()->create([
        'user_id' => $user->id,
        'title' => 'ادفع فاتورة الكهربا',
        'status' => TaskStatus::Pending->value,
    ]);
    $unrelated = Task::query()->create([
        'user_id' => $user->id,
        'title' => 'اتصل بالبنك',
        'status' => TaskStatus::Pending->value,
    ]);

    $linked = pfuLoop($user, $message, ['task_id' => $task->id]);
    $other = pfuLoop($user, $message, ['question' => 'حكيت مع المدير؟']);

    try {
        // Completing an UNRELATED task closes nothing, however many processes try.
        pfuOutcomes(array_map(fn () => pfuRun(['complete', (string) $user->id, (string) $unrelated->id]), range(1, 3)));

        expect(DB::table('follow_ups')->where('id', $linked->id)->value('status'))->toBe(FollowUpStatus::Open->value)
            ->and(DB::table('follow_ups')->where('id', $other->id)->value('status'))->toBe(FollowUpStatus::Open->value);

        $outcomes = pfuOutcomes(array_map(
            fn () => pfuRun(['complete', (string) $user->id, (string) $task->id, '--sleep=30000']),
            range(1, 5),
        ));
        $report = implode(' | ', $outcomes);

        $fresh = DB::table('follow_ups')->where('id', $linked->id)->first();

        expect($fresh->status)->toBe(FollowUpStatus::ResolvedByTask->value, $report)
            ->and($fresh->resolved_at)->not->toBeNull()
            ->and($fresh->next_ask_at)->toBeNull()
            // The unlinked loop is untouched.
            ->and(DB::table('follow_ups')->where('id', $other->id)->value('status'))->toBe(FollowUpStatus::Open->value, $report);

        // And the resolution happened once: no later completion moves the stamp.
        $resolvedAt = $fresh->resolved_at;
        pfuOutcomes(array_map(fn () => pfuRun(['complete', (string) $user->id, (string) $task->id]), range(1, 3)));

        expect(DB::table('follow_ups')->where('id', $linked->id)->value('resolved_at'))->toBe($resolvedAt);
    } finally {
        pfuCleanup($user);
    }
});

/*
|--------------------------------------------------------------------------
| Per-ask physical budget
|--------------------------------------------------------------------------
*/

it('keeps each ask\'s physical attempt budget to itself', function () {
    [$user, , $message] = pfuSubject();
    $followUp = pfuLoop($user, $message);

    try {
        pfuOutcomes([pfuRun(['advance', (string) $followUp->id])]);

        // Ask 1 spends BOTH of its physical attempts on unproven outcomes.
        $first = Reminder::query()->where('follow_up_id', $followUp->id)->firstOrFail();
        $first->forceFill([
            'attempts' => ReminderDispatcher::MAX_ATTEMPTS,
            'dispatched_at' => CarbonImmutable::now()->subHours(30),
            'status' => ReminderStatus::Failed->value,
            'last_error' => ReminderFailureReason::AttemptsExhausted->value,
        ])->save();

        pfuOutcomes([pfuRun(['advance', (string) $followUp->id])]);
        $outcomes = pfuOutcomes(array_map(fn () => pfuRun(['advance', (string) $followUp->id, '--sleep=20000']), range(1, 4)));
        $report = implode(' | ', $outcomes);

        $asks = Reminder::query()->where('follow_up_id', $followUp->id)->orderBy('ask_index')->get();

        // ONE logical ask was spent, not two: the loop's budget counts asks, and
        // each ask carries its own physical ceiling.
        expect($asks)->toHaveCount(2, $report)
            ->and($asks[0]->attempts)->toBe(ReminderDispatcher::MAX_ATTEMPTS)
            ->and($asks[1]->attempts)->toBe(0)
            ->and($asks[1]->claim_token)->toBeNull()
            // Still `awaiting_answer`: the question is outstanding while it is
            // being asked again, which is what keeps a reply correlatable.
            ->and(pfuOutcomes([pfuRun(['state', (string) $followUp->id])])[0])->toBe('awaiting_answer:2:1', $report);
    } finally {
        pfuCleanup($user);
    }
});
