<?php

declare(strict_types=1);

use App\Enums\ChannelType;
use App\Enums\ReminderCancelScope;
use App\Enums\ReminderScheduleStatus;
use App\Enums\ReminderStatus;
use App\Exceptions\Tools\ToolDomainException;
use App\Models\Reminder;
use App\Models\ReminderSchedule;
use App\Services\Reminders\ReminderScheduleService;
use App\Services\Reminders\ReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * A SERIES is a definition; an OCCURRENCE is an ordinary reminder.
 *
 * Almost everything in this file is ultimately one claim: the delivery machinery
 * did not have to change. An occurrence has its own claim token, its own attempt
 * budget and its own outbound message, so `ReminderDispatcher`, the sweeper and
 * the delivery policy treat it exactly as they treat a one-time reminder — and a
 * series can never collapse into one row serving many sends.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    /*
     * TIME IS FROZEN AT A FIXED LOCAL HOUR, and that is not incidental.
     *
     * A daily series at 09:00 over a 30-day horizon produces 30 occurrences when
     * today's 09:00 has already passed and 31 when it has not — so a count
     * asserted against the wall clock is a test that passes in the evening and
     * fails after local midnight. (It does: Asia/Hebron is UTC+3 in September, so
     * a CI run at 21:10 UTC is already tomorrow for the subscriber.) Freezing at
     * 12:00 local makes every count below a fact about the horizon rather than
     * about the hour the suite happened to run.
     */
    test()->travelTo(CarbonImmutable::parse('2026-09-15 12:00', 'Asia/Hebron'));

    whatsappConfigure();
    config([
        'reminders.enabled' => true,
        'reminders.recurrence.enabled' => true,
        'reminders.recurrence.horizon_days' => 30,
        'reminders.recurrence.max_occurrences_per_schedule' => 35,
        'reminders.recurrence.max_active_schedules_per_subscriber' => 10,
    ]);
});

/*
|--------------------------------------------------------------------------
| Materialisation
|--------------------------------------------------------------------------
*/

it('materialises a daily series into independent occurrence rows', function () {
    [$user] = recSubscriber();
    $schedule = recSchedule($user);

    recMaterialise();

    $occurrences = Reminder::query()->where('reminder_schedule_id', $schedule->id)->get();

    expect($occurrences)->not->toBeEmpty();

    foreach ($occurrences as $occurrence) {
        // EACH occurrence is a full reminder in its own right. This is the whole
        // design: no shared attempt counter, no shared claim, no shared message.
        expect($occurrence->isOccurrence())->toBeTrue()
            ->and($occurrence->status)->toBe(ReminderStatus::Pending)
            ->and($occurrence->attempts)->toBe(0)
            ->and($occurrence->claim_token)->toBeNull()
            ->and($occurrence->dispatched_at)->toBeNull()
            ->and($occurrence->sent_at)->toBeNull()
            ->and($occurrence->title)->toBe($schedule->title)
            ->and($occurrence->timezone)->toBe($schedule->timezone)
            ->and($occurrence->channel)->toBe($schedule->channel)
            ->and($occurrence->occurrence_key)->not->toBeNull();
    }
});

it('is idempotent: running materialisation again creates nothing', function () {
    [$user] = recSubscriber();
    $schedule = recSchedule($user);

    $first = recMaterialise();
    $before = recOccurrenceKeys($schedule);
    $second = recMaterialise();

    expect($first['occurrences'])->toBeGreaterThan(0)
        ->and($second['occurrences'])->toBe(0)
        ->and(recOccurrenceKeys($schedule))->toBe($before);
});

it('stops at the 30-day horizon', function () {
    [$user] = recSubscriber();
    $schedule = recSchedule($user);

    recMaterialise();

    $keys = recOccurrenceKeys($schedule);
    $last = Reminder::query()->where('reminder_schedule_id', $schedule->id)->max('remind_at');

    // A daily series: the DAY bound binds before the occurrence bound.
    expect($keys)->toHaveCount(30)
        ->and(CarbonImmutable::parse((string) $last)->diffInDays(CarbonImmutable::now()))
        ->toBeLessThanOrEqual(31);
});

it('stops at 35 uncompleted occurrences per schedule when the count binds first', function () {
    [$user] = recSubscriber();
    // Twice a week over a long horizon: the COUNT is what binds, not the days.
    config(['reminders.recurrence.horizon_days' => 365]);
    $schedule = recSchedule($user, ['pattern' => 'weekly', 'weekdays' => '1,4']);

    recMaterialise();

    expect(recOccurrenceKeys($schedule))->toHaveCount(35);
});

it('counts only UNCOMPLETED occurrences against the bound, so a long series keeps going', function () {
    [$user] = recSubscriber();
    config(['reminders.recurrence.horizon_days' => 365, 'reminders.recurrence.max_occurrences_per_schedule' => 5]);
    $schedule = recSchedule($user, ['pattern' => 'weekly', 'weekdays' => '1,4']);

    recMaterialise();
    expect(recOccurrenceKeys($schedule))->toHaveCount(5);

    // Settle three of them, as delivery eventually does.
    Reminder::query()->where('reminder_schedule_id', $schedule->id)->orderBy('remind_at')->limit(3)
        ->update(['status' => ReminderStatus::Sent->value, 'sent_at' => now()]);

    recMaterialise();

    // History does not hold a slot; otherwise a daily series would eventually
    // stop scheduling itself forever.
    expect(Reminder::query()->where('reminder_schedule_id', $schedule->id)->count())->toBe(8);
});

it('never deletes an existing occurrence to make room at a bound', function () {
    [$user] = recSubscriber();
    $schedule = recSchedule($user);
    recMaterialise();

    $before = recOccurrenceKeys($schedule);

    // Tighten the bound well below what already exists.
    config(['reminders.recurrence.max_occurrences_per_schedule' => 2]);
    recMaterialise();

    // An occurrence may already hold a claim or a delivery record. Reaching a
    // bound stops CREATION; it is never licence to destroy evidence.
    expect(recOccurrenceKeys($schedule))->toBe($before);
});

it('materialises nothing when recurrence is switched off', function () {
    [$user] = recSubscriber();
    $schedule = recSchedule($user);

    config(['reminders.recurrence.enabled' => false]);

    expect(recMaterialise())->toBe(['schedules' => 0, 'occurrences' => 0])
        ->and(recOccurrenceKeys($schedule))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| The per-subscriber cap
|--------------------------------------------------------------------------
*/

it('refuses an eleventh active schedule for one subscriber, and terminates nothing', function () {
    [$user] = recSubscriber();

    foreach (range(1, 10) as $i) {
        recSchedule($user, ['title' => "تذكير {$i}"]);
    }

    expect(fn () => recSchedule($user, ['title' => 'الحادي عشر']))
        ->toThrow(ToolDomainException::class);

    // At the cap a new series is REFUSED. Nothing existing is discarded to make
    // space: a series the subscriber set up is not the platform's to throw away.
    expect(ReminderSchedule::query()->where('user_id', $user->id)->active()->count())->toBe(10);
});

it('frees a slot when a series is cancelled', function () {
    [$user] = recSubscriber();
    $schedules = [];

    foreach (range(1, 10) as $i) {
        $schedules[] = recSchedule($user, ['title' => "تذكير {$i}"]);
    }

    app(ReminderScheduleService::class)->cancel($user, $schedules[0]->id, ReminderCancelScope::Series);

    expect(fn () => recSchedule($user, ['title' => 'بديل']))->not->toThrow(ToolDomainException::class);
});

it('counts the cap per subscriber, never globally', function () {
    [$first] = recSubscriber(e164: '+970599000001');
    [$second] = recSubscriber(e164: '+970599000002');

    foreach (range(1, 10) as $i) {
        recSchedule($first, ['title' => "تذكير {$i}"]);
    }

    // One subscriber at their cap must not block another.
    expect(fn () => recSchedule($second))->not->toThrow(ToolDomainException::class);
});

/*
|--------------------------------------------------------------------------
| Cancellation
|--------------------------------------------------------------------------
*/

it('cancels ONE occurrence through the existing reminder.cancel path, leaving the series running', function () {
    [$user] = recSubscriber();
    $schedule = recSchedule($user);
    recMaterialise();

    $occurrence = Reminder::query()->where('reminder_schedule_id', $schedule->id)->orderBy('remind_at')->first();

    // The EXISTING one-time tool, unchanged, on a `reminder_id`. An occurrence is
    // an ordinary reminder, so no new tool was needed and none was added.
    $result = app(ReminderService::class)->cancel($user, $occurrence->id);

    expect($result['reminder_id'])->toBe($occurrence->id)
        ->and($occurrence->refresh()->status)->toBe(ReminderStatus::Cancelled)
        // The series is untouched and still producing.
        ->and($schedule->refresh()->status)->toBe(ReminderScheduleStatus::Active)
        ->and(Reminder::query()->where('reminder_schedule_id', $schedule->id)
            ->where('status', ReminderStatus::Pending->value)->count())->toBe(29);
});

it('cancels FUTURE occurrences and spares one that is already due', function () {
    [$user] = recSubscriber();
    $schedule = recSchedule($user);
    recMaterialise();

    // One occurrence whose moment has come and which nothing has claimed yet.
    $due = Reminder::query()->where('reminder_schedule_id', $schedule->id)->orderBy('remind_at')->first();
    $due->forceFill(['remind_at' => CarbonImmutable::now()->subMinute()])->save();

    $result = app(ReminderScheduleService::class)->cancel($user, $schedule->id, ReminderCancelScope::Future);

    expect($result['scope'])->toBe('future')
        ->and($result['terminated'])->toBeTrue()
        ->and($schedule->refresh()->status)->toBe(ReminderScheduleStatus::Terminated)
        // «بطّل من بكرا» is not «بطّل هلّق»: the due one keeps its chance to arrive.
        ->and($due->refresh()->status)->toBe(ReminderStatus::Pending)
        ->and(Reminder::query()->where('reminder_schedule_id', $schedule->id)
            ->where('status', ReminderStatus::Cancelled->value)->count())->toBe(29);
});

it('cancels the WHOLE series including an occurrence that is already due', function () {
    [$user] = recSubscriber();
    $schedule = recSchedule($user);
    recMaterialise();

    $due = Reminder::query()->where('reminder_schedule_id', $schedule->id)->orderBy('remind_at')->first();
    $due->forceFill(['remind_at' => CarbonImmutable::now()->subMinute()])->save();

    $result = app(ReminderScheduleService::class)->cancel($user, $schedule->id, ReminderCancelScope::Series);

    expect($result['cancelled_occurrences'])->toBe(30)
        ->and($due->refresh()->status)->toBe(ReminderStatus::Cancelled)
        ->and(Reminder::query()->where('reminder_schedule_id', $schedule->id)
            ->where('status', ReminderStatus::Pending->value)->count())->toBe(0);
});

it('never touches an occurrence that is being delivered, or one already settled', function (ReminderCancelScope $scope) {
    [$user] = recSubscriber();
    $schedule = recSchedule($user);
    recMaterialise();

    $rows = Reminder::query()->where('reminder_schedule_id', $schedule->id)->orderBy('remind_at')->get();

    $processing = $rows[0];
    $processing->forceFill([
        'status' => ReminderStatus::Processing->value,
        'claim_token' => str_repeat('a', 36),
        'claimed_at' => now(),
        'attempts' => 1,
        'dispatched_at' => now(),
    ])->save();

    $sent = $rows[1];
    $sent->forceFill(['status' => ReminderStatus::Sent->value, 'sent_at' => now()])->save();

    $failed = $rows[2];
    $failed->forceFill(['status' => ReminderStatus::Failed->value, 'last_error' => 'too_late'])->save();

    app(ReminderScheduleService::class)->cancel($user, $schedule->id, $scope);

    // A delivery in flight is the claim's, not the schedule's, to retract; and a
    // settled row is a record, not a plan.
    expect($processing->refresh()->status)->toBe(ReminderStatus::Processing)
        ->and($processing->attempts)->toBe(1)
        ->and($sent->refresh()->status)->toBe(ReminderStatus::Sent)
        ->and($failed->refresh()->status)->toBe(ReminderStatus::Failed)
        ->and($failed->last_error)->toBe('too_late');
})->with([
    'future' => [ReminderCancelScope::Future],
    'series' => [ReminderCancelScope::Series],
]);

it('is idempotent when the same series is cancelled twice', function () {
    [$user] = recSubscriber();
    $schedule = recSchedule($user);
    recMaterialise();

    $first = app(ReminderScheduleService::class)->cancel($user, $schedule->id, ReminderCancelScope::Series);
    $second = app(ReminderScheduleService::class)->cancel($user, $schedule->id, ReminderCancelScope::Series);

    expect($first['terminated'])->toBeTrue()
        // Honest about the repeat: the series is stopped, and this call is not
        // the one that stopped it.
        ->and($second['terminated'])->toBeFalse()
        ->and($second['cancelled_occurrences'])->toBe(0);
});

it('refuses to cancel another subscriber series', function () {
    [$owner] = recSubscriber(e164: '+970599000001');
    [$stranger] = recSubscriber(e164: '+970599000002');
    $schedule = recSchedule($owner);

    expect(fn () => app(ReminderScheduleService::class)->cancel($stranger, $schedule->id, ReminderCancelScope::Series))
        ->toThrow(ToolDomainException::class);

    expect($schedule->refresh()->status)->toBe(ReminderScheduleStatus::Active);
});

it('never refills a terminated series', function () {
    [$user] = recSubscriber();
    $schedule = recSchedule($user);
    recMaterialise();

    app(ReminderScheduleService::class)->cancel($user, $schedule->id, ReminderCancelScope::Series);

    // Several passes, as the scheduler would run them.
    recMaterialise();
    recMaterialise();
    recMaterialise();

    expect(Reminder::query()->where('reminder_schedule_id', $schedule->id)
        ->where('status', ReminderStatus::Pending->value)->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Occurrences are independent of one another
|--------------------------------------------------------------------------
*/

it('keeps each occurrence attempt budget isolated from its siblings', function () {
    [$user] = recSubscriber();
    $schedule = recSchedule($user);
    recMaterialise();

    $rows = Reminder::query()->where('reminder_schedule_id', $schedule->id)->orderBy('remind_at')->get();

    // Spend the whole budget on ONE occurrence, exactly as two failed dispatches
    // would.
    $rows[0]->forceFill([
        'attempts' => 2,
        'status' => ReminderStatus::Failed->value,
        'last_error' => 'attempts_exhausted',
    ])->save();

    // Every sibling is untouched: the budget is per row, never per series, so one
    // bad night does not consume tomorrow's reminder.
    foreach ($rows->skip(1) as $sibling) {
        expect($sibling->refresh()->attempts)->toBe(0)
            ->and($sibling->status)->toBe(ReminderStatus::Pending);
    }
});

it('gives each occurrence its own claim and its own outbound message key', function () {
    [$user] = recSubscriber();
    $schedule = recSchedule($user);
    recMaterialise();

    $rows = Reminder::query()->where('reminder_schedule_id', $schedule->id)->orderBy('remind_at')->limit(2)->get();

    $rows[0]->forceFill(['claim_token' => str_repeat('a', 36), 'claimed_at' => now(), 'status' => ReminderStatus::Processing->value])->save();
    $rows[1]->forceFill(['claim_token' => str_repeat('b', 36), 'claimed_at' => now(), 'status' => ReminderStatus::Processing->value])->save();

    // Two concurrent occurrences of ONE series can be claimed independently,
    // which a shared row could not express at all.
    expect($rows[0]->refresh()->isClaimedBy(str_repeat('a', 36)))->toBeTrue()
        ->and($rows[0]->isClaimedBy(str_repeat('b', 36)))->toBeFalse()
        ->and($rows[1]->refresh()->isClaimedBy(str_repeat('b', 36)))->toBeTrue();
});

it('leaves the due scope picking up occurrences exactly as it picks up one-time reminders', function () {
    [$user] = recSubscriber();
    $schedule = recSchedule($user);
    recMaterialise();

    Reminder::query()->where('reminder_schedule_id', $schedule->id)->orderBy('remind_at')->limit(1)
        ->update(['remind_at' => CarbonImmutable::now()->subMinute()]);

    // The dispatcher's own query, unchanged, finds the occurrence. Nothing in the
    // delivery path knows recurrence exists.
    $due = Reminder::query()->due()->get();

    expect($due)->toHaveCount(1)
        ->and($due->first()->reminder_schedule_id)->toBe($schedule->id);
});

/*
|--------------------------------------------------------------------------
| Listing
|--------------------------------------------------------------------------
*/

it('lists only the subscriber own active series, bounded', function () {
    [$owner] = recSubscriber(e164: '+970599000001');
    [$stranger] = recSubscriber(e164: '+970599000002');

    foreach (range(1, 4) as $i) {
        recSchedule($owner, ['title' => "مالي {$i}"]);
    }
    recSchedule($stranger, ['title' => 'لغيري']);

    $listed = app(ReminderScheduleService::class)->list($owner);
    $titles = array_column($listed['schedules'], 'title');

    expect($listed['schedules'])->toHaveCount(4)
        ->and($listed['truncated'])->toBeFalse()
        ->and($titles)->not->toContain('لغيري');

    foreach ($listed['schedules'] as $row) {
        expect($row)->toHaveKeys(['schedule_id', 'title', 'pattern', 'recurrence', 'status', 'next_occurrence_local'])
            // No ownership identifier is ever exposed.
            ->and($row)->not->toHaveKey('user_id');
    }
});

it('reports truncation honestly when the bound cuts the listing short', function () {
    [$user] = recSubscriber();
    config(['reminders.recurrence.list_limit' => 3]);

    foreach (range(1, 5) as $i) {
        recSchedule($user, ['title' => "تذكير {$i}"]);
    }

    $listed = app(ReminderScheduleService::class)->list($user);

    expect($listed['schedules'])->toHaveCount(3)
        ->and($listed['truncated'])->toBeTrue();
});

it('hides terminated series by default and shows them only when asked', function () {
    [$user] = recSubscriber();
    $kept = recSchedule($user, ['title' => 'قائم']);
    $gone = recSchedule($user, ['title' => 'منتهٍ']);

    app(ReminderScheduleService::class)->cancel($user, $gone->id, ReminderCancelScope::Series);

    $default = array_column(app(ReminderScheduleService::class)->list($user)['schedules'], 'title');
    $all = array_column(app(ReminderScheduleService::class)->list($user, ['include_terminated' => true])['schedules'], 'title');

    expect($default)->toBe(['قائم'])
        ->and($all)->toContain('منتهٍ')
        ->and($all)->toContain('قائم');
});

it('never exceeds the hard listing ceiling however high the configured limit', function () {
    [$user] = recSubscriber();
    config(['reminders.recurrence.list_limit' => 9999, 'reminders.recurrence.max_active_schedules_per_subscriber' => 40]);

    foreach (range(1, 30) as $i) {
        recSchedule($user, ['title' => "تذكير {$i}"]);
    }

    // The tool's declared output schema bounds the list; config may narrow that
    // ceiling but can never raise it past what the contract promised.
    expect(app(ReminderScheduleService::class)->list($user)['schedules'])
        ->toHaveCount(ReminderScheduleService::LIST_MAX);
});

/*
|--------------------------------------------------------------------------
| Validation, and the facts a model may not supply
|--------------------------------------------------------------------------
*/

it('refuses a malformed or out-of-scope recurrence definition', function (array $input) {
    [$user] = recSubscriber();

    expect(fn () => recSchedule($user, $input))->toThrow(ToolDomainException::class);
})->with([
    'unknown pattern' => [['pattern' => 'hourly']],
    'bad time' => [['local_time' => '9am']],
    'out-of-range time' => [['local_time' => '25:00']],
    'weekly with no weekdays' => [['pattern' => 'weekly', 'weekdays' => '']],
    'weekly with an invalid weekday' => [['pattern' => 'weekly', 'weekdays' => '1,9']],
    'weekdays on a daily series' => [['pattern' => 'daily', 'weekdays' => '1']],
    'day of month on a daily series' => [['pattern' => 'daily', 'day_of_month' => 5]],
    'monthly day out of range' => [['pattern' => 'monthly', 'day_of_month' => 32]],
    'start date in the past' => [['starts_on' => '2020-01-01']],
    'end before start' => [['starts_on' => '2026-12-01', 'ends_on' => '2026-11-01']],
]);

it('refuses to schedule recurrence for a subscriber with no usable timezone', function () {
    [$user] = recSubscriber();
    $user->forceFill(['timezone' => 'Not/AZone'])->save();

    // Failing is right: a recurring series read in the wrong zone fires at the
    // wrong hour forever, and silently.
    expect(fn () => recSchedule($user->refresh()))->toThrow(ToolDomainException::class);
});

it('takes owner, timezone and channel from context and not from the caller', function () {
    [$user] = recSubscriber('Europe/Berlin');
    $schedule = recSchedule($user);

    expect($schedule->user_id)->toBe($user->id)
        ->and($schedule->timezone)->toBe('Europe/Berlin')
        ->and($schedule->channel->value)->toBe('whatsapp');

    // And the occurrences inherit that context rather than re-deriving it.
    recMaterialise();

    $occurrence = Reminder::query()->where('reminder_schedule_id', $schedule->id)->first();

    expect($occurrence->user_id)->toBe($user->id)
        ->and($occurrence->timezone)->toBe('Europe/Berlin');
});

/*
|--------------------------------------------------------------------------
| One-time reminders are untouched
|--------------------------------------------------------------------------
*/

it('leaves a one-time reminder with no series identity at all', function () {
    [$user] = recSubscriber();

    $created = app(ReminderService::class)->create(
        $user,
        ['title' => 'اتصل على البنك', 'remind_at' => CarbonImmutable::now()->addDay()->format('Y-m-d\TH:i')],
        ChannelType::WhatsApp,
    );

    $reminder = Reminder::query()->findOrFail($created['reminder_id']);

    expect($reminder->reminder_schedule_id)->toBeNull()
        ->and($reminder->occurrence_key)->toBeNull()
        ->and($reminder->occurrence_local_at)->toBeNull()
        ->and($reminder->isOccurrence())->toBeFalse();

    // And materialisation does not touch it.
    recMaterialise();

    expect($reminder->refresh()->status)->toBe(ReminderStatus::Pending);
});
