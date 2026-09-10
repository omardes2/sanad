<?php

declare(strict_types=1);

use App\Enums\ChannelAccountStatus;
use App\Enums\ChannelType;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\ReminderFailureReason;
use App\Enums\ReminderScheduleStatus;
use App\Enums\ReminderStatus;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Reminder;
use App\Models\ReminderSchedule;
use App\Models\User;
use App\Services\Reminders\ReminderDispatcher;
use App\Services\Reminders\ReminderScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * GENUINE parallel tests for RECURRING reminders on PostgreSQL — separate PHP
 * processes, real row locks, no shared transaction.
 *
 * Recurrence adds one new class of race to the delivery races proven next door:
 * several processes deciding what a series OWES. What must hold:
 *
 *  - concurrent materialisers produce ONE row per occurrence identity, never a
 *    duplicate — the unique key decides it, not timing and not the cursor;
 *  - a cancellation that commits while materialisers are mid-flight leaves NO
 *    live occurrence behind, and a terminated series is never refilled
 *    afterwards, however many runs arrive;
 *  - every occurrence carries its OWN claim and its OWN attempt budget, so
 *    exhausting one occurrence of a series neither spends nor poisons the next
 *    one — the invariant the whole "occurrence is an ordinary reminder" design
 *    exists to buy;
 *  - the per-subscriber cap on active series holds under concurrent creation,
 *    and reaching it refuses the new series rather than discarding an old one.
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
        'reminders.enabled' => true,
        'reminders.recurrence.enabled' => true,
        'reminders.recurrence.horizon_days' => 30,
        'reminders.recurrence.max_occurrences_per_schedule' => 35,
        'reminders.recurrence.max_active_schedules_per_subscriber' => 10,
        'reminders.max_lateness_minutes' => 600,
        'reminders.lease_seconds' => 300,
        'whatsapp.enabled' => true,
        'whatsapp.access_token' => 'TEST_ACCESS_TOKEN',
        'whatsapp.phone_number_id' => 'PNID_123',
        'whatsapp.graph_base_url' => 'https://graph.facebook.com',
        'whatsapp.graph_version' => 'v21.0',
    ]);
});

/**
 * One subscriber with a WhatsApp account, an open free-form window and a real
 * conversation — committed, so separate processes can see it.
 *
 * @return array{0: User, 1: ChannelAccount, 2: Conversation}
 */
function prrSubject(): array
{
    $user = User::factory()->create([
        'is_admin' => false,
        'timezone' => 'Asia/Hebron',
        'locale' => 'ar',
        'email' => 'rec-probe-'.bin2hex(random_bytes(6)).'@example.test',
    ]);

    $account = ChannelAccount::factory()->for($user)->create([
        'channel' => ChannelType::WhatsApp,
        'external_identifier' => '+97059'.random_int(1000000, 9999999),
        'status' => ChannelAccountStatus::Active,
    ]);

    $conversation = Conversation::factory()->for($user)->create(['channel_account_id' => $account->id]);

    // The subscriber spoke ten minutes ago, so the free-form window is open and
    // delivery is not refused for the unrelated reason of a missing template.
    Message::factory()->for($user)->for($conversation)->create([
        'direction' => MessageDirection::Inbound,
        'type' => MessageType::Text,
        'text_content' => 'ذكرني كل يوم',
        'created_at' => CarbonImmutable::now()->subMinutes(10),
    ]);

    return [$user, $account, $conversation];
}

/** One series, created through the domain service — the same path the tool takes. */
function prrSchedule(User $subscriber, array $input = []): ReminderSchedule
{
    $created = app(ReminderScheduleService::class)->create(
        $subscriber,
        array_merge(['title' => 'اشرب الدوا', 'pattern' => 'daily', 'local_time' => '09:00'], $input),
        ChannelType::WhatsApp,
    );

    return ReminderSchedule::query()->findOrFail($created['schedule_id']);
}

/** @param list<string> $args */
function prrRun(array $args): Process
{
    $p = new Process(['php', 'artisan', 'sanad:reminder-recurrence-probe', ...$args], base_path());
    $p->start();

    return $p;
}

/** @param list<string> $args */
function prrDispatch(array $args): Process
{
    $p = new Process(['php', 'artisan', 'sanad:reminder-dispatch-probe', ...$args], base_path());
    $p->start();

    return $p;
}

/**
 * @param  list<Process>  $processes
 * @return list<string>
 */
function prrOutcomes(array $processes): array
{
    $outcomes = [];

    foreach ($processes as $p) {
        $p->wait();
        expect($p->getExitCode())->toBe(0, $p->getOutput().$p->getErrorOutput());
        $outcomes[] = trim($p->getOutput());
    }

    return $outcomes;
}

/** How many rows these materialisers claim to have created, in total. */
function prrCreated(array $outcomes): int
{
    return array_sum(array_map(
        static fn (string $o): int => str_starts_with($o, 'created:') ? (int) explode(':', $o)[1] : 0,
        $outcomes,
    ));
}

/** How many of these delivery workers actually made a physical request. */
function prrSends(array $outcomes): int
{
    return count(array_filter(
        $outcomes,
        static fn (string $o): bool => $o !== 'missing' && ! str_starts_with($o, 'nosend:'),
    ));
}

/** The claim token this `claim` probe took for one reminder id, if any. */
function prrToken(string $outcome, int $reminderId): ?string
{
    foreach (explode(',', substr($outcome, strlen('claimed:'))) as $pair) {
        [$id, $token] = array_pad(explode(':', $pair), 2, null);

        if ((int) $id === $reminderId && is_string($token) && $token !== '') {
            return $token;
        }
    }

    return null;
}

/** Claim one known reminder across N racing processes and return its token. */
function prrClaim(int $reminderId): string
{
    $outcomes = prrOutcomes([prrDispatch(['claim', '10'])]);
    $token = prrToken($outcomes[0], $reminderId);

    expect($token)->not->toBeNull(implode(' | ', $outcomes));

    return (string) $token;
}

function prrCleanup(User $user): void
{
    $reminderIds = DB::table('reminders')->where('user_id', $user->id)->pluck('id');
    DB::table('usage_events')->where('user_id', $user->id)->delete();
    DB::table('messages')->where('user_id', $user->id)->delete();
    DB::table('reminders')->whereIn('id', $reminderIds)->delete();
    DB::table('reminder_schedules')->where('user_id', $user->id)->delete();
    DB::table('conversations')->where('user_id', $user->id)->delete();
    DB::table('channel_accounts')->where('user_id', $user->id)->delete();
    $user->delete();
}

/*
|--------------------------------------------------------------------------
| Materialisation
|--------------------------------------------------------------------------
*/

it('of 6 concurrent materialisers of one series, every occurrence identity exists exactly once', function () {
    [$user] = prrSubject();
    $schedule = prrSchedule($user);

    try {
        $outcomes = prrOutcomes(array_map(
            fn () => prrRun(['materialise', (string) $schedule->id, '--sleep=40000']),
            range(1, 6),
        ));

        $report = implode(' | ', $outcomes);

        $keys = DB::table('reminders')
            ->where('reminder_schedule_id', $schedule->id)
            ->pluck('occurrence_key')
            ->all();

        $rows = count($keys);

        // The unique key is the arbiter, so a concurrent run is a NO-OP and never
        // a second row: distinct identities equal total rows.
        expect(array_unique($keys))->toHaveCount($rows, $report)
            // A daily series over a 30-day horizon: every day inside it, once.
            ->and($rows)->toBeGreaterThanOrEqual(30, $report)
            ->and($rows)->toBeLessThanOrEqual((int) config('reminders.recurrence.max_occurrences_per_schedule'), $report)
            // And the processes' own accounting agrees: the rows were created
            // once in total, not once per process.
            ->and(prrCreated($outcomes))->toBe($rows, $report)
            // Nothing threw: a duplicate is skipped inside a savepoint and can
            // never abort the batch around it.
            ->and(array_filter($outcomes, static fn (string $o): bool => str_starts_with($o, 'threw:')))->toHaveCount(0, $report);

        // Every occurrence is an ORDINARY pending reminder with its own identity
        // and its own untouched delivery state.
        expect(DB::table('reminders')->where('reminder_schedule_id', $schedule->id)->where('status', ReminderStatus::Pending->value)->count())->toBe($rows)
            ->and(DB::table('reminders')->where('reminder_schedule_id', $schedule->id)->where('attempts', 0)->count())->toBe($rows)
            ->and(DB::table('reminders')->where('reminder_schedule_id', $schedule->id)->whereNull('claim_token')->count())->toBe($rows);
    } finally {
        prrCleanup($user);
    }
});

it('holds the occurrence bound under concurrent materialisers, and deletes nothing to make room', function () {
    [$user] = prrSubject();
    $schedule = prrSchedule($user);

    try {
        // A horizon twice the count bound, so the COUNT is what binds: a daily
        // series over the default 30-day window would stop at 30 and prove
        // nothing about the cap. The horizon is passed to the CHILD processes,
        // which are the ones actually materialising.
        prrOutcomes(array_map(
            fn () => prrRun(['materialise', (string) $schedule->id, '--horizon=70', '--sleep=30000']),
            range(1, 4),
        ));

        $first = DB::table('reminders')->where('reminder_schedule_id', $schedule->id)->pluck('occurrence_key')->all();

        expect($first)->toHaveCount(35)
            ->and(array_unique($first))->toHaveCount(35);

        // More runs at the bound create nothing and remove nothing.
        $again = prrOutcomes(array_map(
            fn () => prrRun(['materialise', (string) $schedule->id, '--horizon=70']),
            range(1, 3),
        ));

        $second = DB::table('reminders')->where('reminder_schedule_id', $schedule->id)->pluck('occurrence_key')->all();

        expect(prrCreated($again))->toBe(0, implode(' | ', $again))
            ->and($second)->toHaveCount(35)
            ->and(array_values(array_diff($first, $second)))->toBe([]);
    } finally {
        prrCleanup($user);
    }
});

/*
|--------------------------------------------------------------------------
| Termination racing materialisation
|--------------------------------------------------------------------------
*/

it('a cancellation committing mid-flight leaves no live occurrence, whatever the materialisers were doing', function () {
    [$user] = prrSubject();
    $schedule = prrSchedule($user);

    try {
        // A first pass so there is real work for the cancellation to retract.
        prrOutcomes([prrRun(['materialise', (string) $schedule->id])]);
        expect(DB::table('reminders')->where('reminder_schedule_id', $schedule->id)->count())->toBeGreaterThan(0);

        // Now the race: four materialisers and one cancellation, all in flight.
        $processes = array_map(
            fn () => prrRun(['materialise', (string) $schedule->id, '--sleep=40000']),
            range(1, 4),
        );
        $processes[] = prrRun(['cancel', (string) $schedule->id, 'series', '--sleep=40000']);

        $outcomes = prrOutcomes($processes);
        $report = implode(' | ', $outcomes);

        $schedule->refresh();

        // Whoever won the lock, the end state is the one the subscriber asked
        // for: the series is stopped and NOTHING of it is still queued to fire.
        // A materialiser that planned from the pre-cancellation version is
        // fenced out by the version check, so it cannot insert afterwards.
        expect($schedule->status)->toBe(ReminderScheduleStatus::Terminated, $report)
            ->and($schedule->terminated_at)->not->toBeNull()
            ->and(DB::table('reminders')
                ->where('reminder_schedule_id', $schedule->id)
                ->whereIn('status', [ReminderStatus::Pending->value, ReminderStatus::Processing->value])
                ->count())->toBe(0, $report);
    } finally {
        prrCleanup($user);
    }
});

it('never refills a terminated series, however many materialisers arrive afterwards', function () {
    [$user] = prrSubject();
    $schedule = prrSchedule($user);

    try {
        prrOutcomes([prrRun(['materialise', (string) $schedule->id])]);
        $before = DB::table('reminders')->where('reminder_schedule_id', $schedule->id)->count();
        expect($before)->toBeGreaterThan(0);

        $cancelled = prrOutcomes([prrRun(['cancel', (string) $schedule->id, 'series'])])[0];
        expect($cancelled)->toBe('cancelled:'.$before.':1');

        // Eight runs against a dead definition.
        $outcomes = prrOutcomes(array_map(fn () => prrRun(['materialise', (string) $schedule->id]), range(1, 8)));

        $schedule->refresh();
        $report = implode(' | ', $outcomes);

        expect(prrCreated($outcomes))->toBe(0, $report)
            ->and(DB::table('reminders')->where('reminder_schedule_id', $schedule->id)->count())->toBe($before, $report)
            ->and(DB::table('reminders')->where('reminder_schedule_id', $schedule->id)->where('status', ReminderStatus::Cancelled->value)->count())->toBe($before)
            // The cursor is an optimisation, so it is NOT what stopped them: the
            // status and version fence is, and it held for every one of the eight.
            ->and($schedule->status)->toBe(ReminderScheduleStatus::Terminated);
    } finally {
        prrCleanup($user);
    }
});

it('an idempotent repeat of a cancellation is honest about not being the call that stopped it', function () {
    [$user] = prrSubject();
    $schedule = prrSchedule($user);

    try {
        prrOutcomes([prrRun(['materialise', (string) $schedule->id])]);

        $outcomes = prrOutcomes(array_map(
            fn () => prrRun(['cancel', (string) $schedule->id, 'series', '--sleep=30000']),
            range(1, 5),
        ));

        $report = implode(' | ', $outcomes);

        // Exactly one caller terminated the series; the rest say so.
        $terminators = array_filter($outcomes, static fn (string $o): bool => str_ends_with($o, ':1'));

        expect($terminators)->toHaveCount(1, $report)
            // And the occurrences were cancelled once, not five times over.
            ->and(array_sum(array_map(
                static fn (string $o): int => (int) (explode(':', $o)[1] ?? 0),
                $outcomes,
            )))->toBe(DB::table('reminders')->where('reminder_schedule_id', $schedule->id)->count(), $report);
    } finally {
        prrCleanup($user);
    }
});

/*
|--------------------------------------------------------------------------
| Per-occurrence delivery identity
|--------------------------------------------------------------------------
*/

it('gives every occurrence its own claim and its own attempt budget under racing workers', function () {
    [$user] = prrSubject();
    $schedule = prrSchedule($user);

    try {
        prrOutcomes([prrRun(['materialise', (string) $schedule->id])]);

        /** @var list<Reminder> $occurrences */
        $occurrences = Reminder::query()
            ->where('reminder_schedule_id', $schedule->id)
            ->orderBy('remind_at')
            ->limit(2)
            ->get()
            ->all();

        expect($occurrences)->toHaveCount(2)
            ->and($occurrences[0]->occurrence_key)->not->toBe($occurrences[1]->occurrence_key);

        [$first, $second] = $occurrences;

        // Only the FIRST occurrence is due, so the races below are about it
        // alone and the second one's state is an untouched control.
        DB::table('reminders')->where('id', $first->id)
            ->update(['remind_at' => CarbonImmutable::now()->subMinute()]);

        // Attempt 1 on the first occurrence, with an unproven outcome: the
        // provider may have accepted and charged before failing to answer.
        $tokenA = prrClaim($first->id);
        $round1 = prrOutcomes(array_map(
            fn () => prrDispatch(['deliver', (string) $first->id, $tokenA, '--outcome=unknown']),
            range(1, 4),
        ));

        $first->refresh();
        expect(prrSends($round1))->toBe(1, implode(' | ', $round1))
            ->and($first->attempts)->toBe(1)
            ->and($first->status)->toBe(ReminderStatus::Processing);

        // Its lease goes stale, the sweeper returns it, and the one permitted
        // retry is raced for.
        DB::table('reminders')->where('id', $first->id)
            ->update(['claimed_at' => CarbonImmutable::now()->subSeconds(600)]);
        prrOutcomes([prrDispatch(['sweep'])]);

        $tokenB = prrClaim($first->id);
        expect($tokenB)->not->toBe($tokenA);

        $round2 = prrOutcomes(array_map(
            fn () => prrDispatch(['deliver', (string) $first->id, $tokenB, '--outcome=unknown']),
            range(1, 4),
        ));

        $first->refresh();
        $second->refresh();
        $report = implode(' | ', array_merge($round1, $round2));

        // The first occurrence has spent its ceiling. It is still `processing`,
        // because the second outcome is unproven and the row is not closed by
        // guessing — the sweeper retires it below.
        expect(prrSends($round2))->toBe(1, $report)
            ->and($first->attempts)->toBe(2, $report)
            ->and($first->attempts)->toBeLessThanOrEqual(ReminderDispatcher::MAX_ATTEMPTS)
            ->and($first->status)->toBe(ReminderStatus::Processing, $report)
            // …and the next occurrence of the SAME series is untouched by any of
            // it. This is the whole reason an occurrence is its own row: one
            // exhausted delivery must not spend the series' next promise.
            ->and($second->attempts)->toBe(0, $report)
            ->and($second->status)->toBe(ReminderStatus::Pending, $report)
            ->and($second->claim_token)->toBeNull()
            ->and($second->dispatched_at)->toBeNull();

        // The exhausted occurrence is retired by a sweeper — and the sweep that
        // closes it leaves the sibling pending, because a sweep is per row too.
        DB::table('reminders')->where('id', $first->id)
            ->update(['claimed_at' => CarbonImmutable::now()->subSeconds(600)]);
        expect(prrOutcomes([prrDispatch(['sweep'])]))->toBe(['swept:0:1']);

        $first->refresh();
        $second->refresh();

        expect($first->status)->toBe(ReminderStatus::Failed)
            ->and($first->attempts)->toBe(2)
            ->and($first->failureReason())->toBe(ReminderFailureReason::AttemptsExhausted)
            ->and($second->status)->toBe(ReminderStatus::Pending)
            ->and($second->attempts)->toBe(0);

        // And the next occurrence then delivers normally, on its own budget,
        // with its own outbound message.
        DB::table('reminders')->where('id', $second->id)
            ->update(['remind_at' => CarbonImmutable::now()->subMinute()]);

        $tokenC = prrClaim($second->id);
        expect($tokenC)->not->toBe($tokenB);

        prrOutcomes(array_map(
            fn () => prrDispatch(['deliver', (string) $second->id, $tokenC]),
            range(1, 4),
        ));

        $first->refresh();
        $second->refresh();

        $messages = DB::table('messages')
            ->whereIn('reminder_id', [$first->id, $second->id])
            ->pluck('id', 'reminder_id');

        expect($second->status)->toBe(ReminderStatus::Sent)
            ->and($second->attempts)->toBe(1)
            // `messages.reminder_id` is UNIQUE, which is exactly WHY one row
            // cannot serve two deliveries: each occurrence carries its own
            // outbound message, and the two are different rows.
            ->and($messages)->toHaveCount(2)
            ->and($messages[$first->id])->not->toBe($messages[$second->id])
            // Billed for the one send that was actually accepted — and not for
            // the two unproven requests the first occurrence spent.
            ->and(DB::table('usage_events')->where('correlation_id', 'reminder:'.$second->id)->count())->toBe(1)
            ->and(DB::table('usage_events')->where('correlation_id', 'reminder:'.$first->id)->count())->toBe(0)
            // The exhausted occurrence stayed exhausted; nothing about the
            // successful sibling reopened it.
            ->and($first->status)->toBe(ReminderStatus::Failed)
            ->and($first->attempts)->toBe(2);
    } finally {
        prrCleanup($user);
    }
});

/*
|--------------------------------------------------------------------------
| The per-subscriber cap
|--------------------------------------------------------------------------
*/

it('of 14 concurrent series creations at a cap of 10, exactly 10 exist and 4 are refused', function () {
    [$user] = prrSubject();

    try {
        $outcomes = prrOutcomes(array_map(
            fn () => prrRun(['create', (string) $user->id, '--sleep=25000']),
            range(1, 14),
        ));

        $report = implode(' | ', $outcomes);

        $created = array_filter($outcomes, static fn (string $o): bool => str_starts_with($o, 'created:'));
        $refused = array_filter($outcomes, static fn (string $o): bool => $o === 'refused:ToolDomainException');

        // The cap is enforced on the SUBSCRIBER'S row, so two creations one
        // below it cannot both count room: `FOR UPDATE` can lock the user, and
        // cannot lock a schedule that does not exist yet.
        expect(DB::table('reminder_schedules')->where('user_id', $user->id)->where('status', ReminderScheduleStatus::Active->value)->count())
            ->toBe(10, $report)
            ->and($created)->toHaveCount(10, $report)
            ->and($refused)->toHaveCount(4, $report)
            // Nothing was terminated to make space: a series the subscriber set
            // up is not the platform's to discard.
            ->and(DB::table('reminder_schedules')->where('user_id', $user->id)->where('status', ReminderScheduleStatus::Terminated->value)->count())
            ->toBe(0, $report);

        // Cancelling one frees exactly one slot — no more.
        $freed = (int) DB::table('reminder_schedules')->where('user_id', $user->id)->orderBy('id')->value('id');
        prrOutcomes([prrRun(['cancel', (string) $freed, 'series'])]);

        $after = prrOutcomes(array_map(fn () => prrRun(['create', (string) $user->id, '--sleep=20000']), range(1, 4)));
        $afterReport = implode(' | ', $after);

        expect(array_filter($after, static fn (string $o): bool => str_starts_with($o, 'created:')))->toHaveCount(1, $afterReport)
            ->and(DB::table('reminder_schedules')->where('user_id', $user->id)->where('status', ReminderScheduleStatus::Active->value)->count())
            ->toBe(10, $afterReport);
    } finally {
        prrCleanup($user);
    }
});
