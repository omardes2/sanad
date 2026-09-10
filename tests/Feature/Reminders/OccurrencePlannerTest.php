<?php

declare(strict_types=1);

use App\Enums\ReminderPattern;
use App\Models\ReminderSchedule;
use App\Support\Reminders\LocalInstant;
use App\Support\Reminders\OccurrencePlanner;
use Carbon\CarbonImmutable;

/**
 * The CALENDAR, proved without a single row in the database.
 *
 * The planner is pure, which is why this file can be exhaustive about the cases
 * that actually break recurring reminders in production: a daylight transition,
 * a local time that does not exist, a local time that happens twice, and
 * February. None of those need a schedule to be stored, a job to run or a
 * provider to answer — and a bug in any of them fires at the wrong hour, forever
 * and silently, which is exactly the kind of bug a test must catch rather than an
 * operator.
 *
 * ZONES ARE REAL ONES, chosen deliberately:
 *   Asia/Hebron          — the subscribers' own zone, and it observes DST.
 *   Europe/Berlin        — a clean one-hour transition.
 *   Australia/Lord_Howe  — a THIRTY-MINUTE transition, which is why nothing here
 *                          is allowed to assume a gap is an hour.
 */
function plannerSchedule(array $attributes = []): ReminderSchedule
{
    $schedule = new ReminderSchedule;

    $schedule->forceFill(array_merge([
        'id' => 1,
        'user_id' => 1,
        'title' => 'اشرب الدوا',
        'channel' => 'whatsapp',
        'timezone' => 'Asia/Hebron',
        'pattern' => ReminderPattern::Daily->value,
        'local_time' => '09:00',
        'starts_on' => '2026-01-01',
        'status' => 'active',
    ], $attributes));

    return $schedule;
}

function planned(ReminderSchedule $schedule, string $from, string $through, int $limit = 10): array
{
    return app(OccurrencePlanner::class)->plan($schedule, CarbonImmutable::parse($from, 'UTC'), $through, $limit);
}

/** The nominal identities, which is what the unique index is built on. */
function plannedKeys(array $occurrences): array
{
    return array_map(static fn ($o): string => $o->key, $occurrences);
}

/*
|--------------------------------------------------------------------------
| The three V1 patterns
|--------------------------------------------------------------------------
*/

it('plans a daily series on consecutive local dates', function () {
    $keys = plannedKeys(planned(plannerSchedule(), '2026-09-10 12:00', '2026-09-14', 10));

    expect($keys)->toBe([
        '2026-09-11T09:00',
        '2026-09-12T09:00',
        '2026-09-13T09:00',
        '2026-09-14T09:00',
    ]);
});

it('plans a weekly series on ONE declared weekday', function () {
    // 3 = Wednesday, ISO.
    $keys = plannedKeys(planned(plannerSchedule(['pattern' => 'weekly', 'weekdays' => [3]]), '2026-09-10 12:00', '2026-10-01', 10));

    expect($keys)->toBe([
        '2026-09-16T09:00',
        '2026-09-23T09:00',
        '2026-09-30T09:00',
    ]);
});

it('plans a weekly series on SEVERAL declared weekdays, in date order', function () {
    // Monday and Thursday.
    $keys = plannedKeys(planned(plannerSchedule(['pattern' => 'weekly', 'weekdays' => [1, 4]]), '2026-09-10 12:00', '2026-09-22', 10));

    expect($keys)->toBe([
        '2026-09-14T09:00',  // Mon
        '2026-09-17T09:00',  // Thu
        '2026-09-21T09:00',  // Mon
    ]);
});

it('plans a monthly series on the declared day of month', function () {
    $keys = plannedKeys(planned(plannerSchedule(['pattern' => 'monthly', 'day_of_month' => 5]), '2026-09-10 12:00', '2026-12-31', 10));

    expect($keys)->toBe([
        '2026-10-05T09:00',
        '2026-11-05T09:00',
        '2026-12-05T09:00',
    ]);
});

it('honours the series own end date', function () {
    $keys = plannedKeys(planned(plannerSchedule(['ends_on' => '2026-09-13']), '2026-09-10 12:00', '2026-09-30', 10));

    expect($keys)->toBe(['2026-09-11T09:00', '2026-09-12T09:00', '2026-09-13T09:00']);
});

it('never manufactures an occurrence whose moment has already passed', function () {
    // Mid-afternoon on the 11th: that day's 09:00 is gone and is NOT back-filled,
    // which is what stops an outage producing a flood of stale reminders.
    $keys = plannedKeys(planned(plannerSchedule(), '2026-09-11 14:00', '2026-09-13', 10));

    expect($keys)->toBe(['2026-09-12T09:00', '2026-09-13T09:00']);
});

/*
|--------------------------------------------------------------------------
| February, and the leap year
|--------------------------------------------------------------------------
*/

it('clamps a monthly day of month to the last valid day — February, non-leap', function () {
    $keys = plannedKeys(planned(plannerSchedule(['pattern' => 'monthly', 'day_of_month' => 31]), '2027-01-05 00:00', '2027-04-30', 10));

    // Clamped, never skipped: «آخر الشهر ذكرني أدفع الإيجار» must not miss
    // February, and never overflows into March the way `+1 month` would.
    expect($keys)->toBe([
        '2027-01-31T09:00',
        '2027-02-28T09:00',
        '2027-03-31T09:00',
        '2027-04-30T09:00',
    ]);
});

it('clamps to 29 February in a LEAP year', function () {
    $keys = plannedKeys(planned(plannerSchedule(['pattern' => 'monthly', 'day_of_month' => 31]), '2028-01-05 00:00', '2028-03-31', 10));

    expect($keys)->toBe(['2028-01-31T09:00', '2028-02-29T09:00', '2028-03-31T09:00']);
});

it('produces a deterministic, duplicate-safe identity for a clamped month', function () {
    $schedule = plannerSchedule(['pattern' => 'monthly', 'day_of_month' => 30]);

    $first = plannedKeys(planned($schedule, '2027-02-01 00:00', '2027-02-28', 5));
    $again = plannedKeys(planned($schedule, '2027-02-01 00:00', '2027-02-28', 5));

    // The same month always yields the same key, so re-materialising cannot
    // create a second row for one occurrence.
    expect($first)->toBe(['2027-02-28T09:00'])
        ->and($again)->toBe($first);
});

/*
|--------------------------------------------------------------------------
| Daylight saving
|--------------------------------------------------------------------------
*/

it('keeps the same local wall clock on both sides of a DST transition', function () {
    $occurrences = planned(plannerSchedule(), '2026-03-26 12:00', '2026-03-30', 10);
    $byKey = [];

    foreach ($occurrences as $occurrence) {
        $byKey[$occurrence->key] = $occurrence;
    }

    // Hebron springs forward on 28 March 2026. The WALL CLOCK is the promise, so
    // every occurrence is still 09:00 local — and the UTC instants differ across
    // the transition, which is the proof that it was recomputed rather than
    // derived by adding 24 hours to the previous one.
    foreach (['2026-03-27T09:00', '2026-03-28T09:00', '2026-03-29T09:00'] as $key) {
        expect($byKey)->toHaveKey($key)
            ->and($byKey[$key]->remindAt->copy()->setTimezone('Asia/Hebron')->format('H:i'))->toBe('09:00');
    }

    expect($byKey['2026-03-27T09:00']->remindAt->format('H:i'))->toBe('07:00')
        ->and($byKey['2026-03-29T09:00']->remindAt->format('H:i'))->toBe('06:00');
});

it('shifts a nonexistent local time forward by the ACTUAL gap, not by an assumed hour', function (string $zone, string $date, string $requested, string $expectedLocal, int $gapMinutes) {
    $schedule = plannerSchedule(['timezone' => $zone, 'local_time' => $requested]);
    $occurrence = app(OccurrencePlanner::class)->at($schedule, $date);

    expect(LocalInstant::isNonexistent("{$date} {$requested}", $zone))->toBeTrue()
        ->and($occurrence->shifted)->toBeTrue()
        // The requested MINUTE survives: the subscriber asked for half past, and
        // snapping to the first valid instant would have given them o'clock.
        ->and($occurrence->remindAt->copy()->setTimezone($zone)->format('H:i'))->toBe($expectedLocal)
        // And the identity is still what they asked for, so re-materialising is
        // idempotent even across a timezone-rules update.
        ->and($occurrence->key)->toBe($date.'T'.$requested);

    // The gap really is the one the zone declares, and it is not always an hour.
    $requestedMinutes = (int) substr($requested, 0, 2) * 60 + (int) substr($requested, 3, 2);
    $resolvedMinutes = (int) $occurrence->remindAt->copy()->setTimezone($zone)->format('H') * 60
        + (int) $occurrence->remindAt->copy()->setTimezone($zone)->format('i');

    expect($resolvedMinutes - $requestedMinutes)->toBe($gapMinutes);
})->with([
    'Hebron — one hour' => ['Asia/Hebron', '2026-03-28', '02:30', '03:30', 60],
    'Berlin — one hour' => ['Europe/Berlin', '2026-03-29', '02:30', '03:30', 60],
    // The case that forbids assuming an hour anywhere in the implementation.
    'Lord Howe — THIRTY MINUTES' => ['Australia/Lord_Howe', '2026-10-04', '02:15', '02:45', 30],
]);

it('resolves an ambiguous local time to exactly ONE occurrence, the first of the two', function () {
    // Berlin falls back on 25 October 2026: 02:30 local happens twice, at 00:30Z
    // and at 01:30Z. PHP's own normalisation picks the SECOND, so the first had to
    // be chosen explicitly — and one delivery is what the subscriber expects.
    $nominal = '2026-10-25 02:30';

    expect(LocalInstant::isAmbiguous($nominal, 'Europe/Berlin'))->toBeTrue()
        ->and(LocalInstant::resolve($nominal, 'Europe/Berlin')->format('Y-m-d H:i'))->toBe('2026-10-25 00:30');

    // And the planner emits it once, not twice.
    $keys = plannedKeys(planned(
        plannerSchedule(['timezone' => 'Europe/Berlin', 'local_time' => '02:30']),
        '2026-10-24 12:00',
        '2026-10-26',
        10,
    ));

    expect(array_count_values($keys)['2026-10-25T02:30'] ?? 0)->toBe(1);
});

it('treats an ordinary local time as neither shifted nor ambiguous', function () {
    expect(LocalInstant::isNonexistent('2026-09-11 09:00', 'Asia/Hebron'))->toBeFalse()
        ->and(LocalInstant::isAmbiguous('2026-09-11 09:00', 'Asia/Hebron'))->toBeFalse()
        ->and(app(OccurrencePlanner::class)->at(plannerSchedule(), '2026-09-11')->shifted)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Bounds and malformed definitions
|--------------------------------------------------------------------------
*/

it('never returns more than the caller asked for', function () {
    expect(planned(plannerSchedule(), '2026-09-10 12:00', '2026-12-31', 4))->toHaveCount(4)
        ->and(planned(plannerSchedule(), '2026-09-10 12:00', '2026-12-31', 0))->toBe([]);
});

it('plans nothing for a weekly series with no weekdays, rather than guessing one', function () {
    expect(planned(plannerSchedule(['pattern' => 'weekly', 'weekdays' => []]), '2026-09-10 12:00', '2026-10-10', 10))->toBe([]);
});

it('plans nothing before the series own start date', function () {
    $keys = plannedKeys(planned(plannerSchedule(['starts_on' => '2026-10-01']), '2026-09-10 12:00', '2026-10-03', 10));

    expect($keys)->toBe(['2026-10-01T09:00', '2026-10-02T09:00', '2026-10-03T09:00']);
});
