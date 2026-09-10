<?php

declare(strict_types=1);

namespace App\Support\Reminders;

use App\Data\Reminders\PlannedOccurrence;
use App\Enums\ReminderPattern;
use App\Models\ReminderSchedule;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Which nominal local moments does a schedule fall on, and where does each one
 * land in real time?
 *
 * PURE AND STATELESS. It reads a schedule and returns plans; it never touches
 * the database, never decides how many to create, and never knows about claims
 * or deliveries. The materialiser owns the bounds and the writes, the dispatcher
 * owns delivery, and this owns the calendar — so the calendar can be tested
 * exhaustively without a single row existing.
 *
 * THE WALK IS OVER NOMINAL LOCAL DATES, never over instants. «كل يوم الساعة ٩»
 * is a statement about a wall clock, so the planner steps day by day through the
 * subscriber's own calendar and converts each nominal moment separately through
 * LocalInstant. Adding 86400 seconds to the previous instant would have been the
 * DST bug this phase exists to avoid: it drifts by the gap at every transition.
 *
 * MONTHLY CLAMPS to the last valid day of the month — day 31 becomes 28 in
 * February 2027 and 29 in February 2028. Clamping rather than skipping, because
 * «آخر الشهر ذكرني أدفع الإيجار» must not silently miss February; and the
 * identity stays deterministic because the key is built from the CLAMPED date,
 * so the same month always produces the same key and the unique index does the
 * rest.
 */
final class OccurrencePlanner
{
    /**
     * The occurrences of $schedule whose nominal local time falls after $after
     * and no later than $through (a local date), capped at $limit.
     *
     * @param  CarbonImmutable  $after  occurrences at or before this instant are NOT planned
     * @param  string  $through  the last local date to consider, `Y-m-d`
     * @return list<PlannedOccurrence>
     */
    public function plan(ReminderSchedule $schedule, CarbonImmutable $after, string $through, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        $zone = $schedule->timezone;
        $cursor = $this->firstLocalDate($schedule, $after, $zone);
        $planned = [];

        // A hard walk bound so a malformed schedule can never spin: at most one
        // step per day in the window, plus a month of slack for monthly series.
        $steps = 0;
        $maxSteps = 400;

        while ($cursor <= $through && count($planned) < $limit && $steps++ < $maxSteps) {
            $date = $this->matchingDate($schedule, $cursor);

            if ($date === null || $date > $through) {
                break;
            }

            if ($schedule->windowClosed($date)) {
                break;
            }

            $occurrence = $this->at($schedule, $date);

            // Strictly after: an occurrence whose moment has already passed is
            // never manufactured retroactively, so an outage produces no flood.
            if ($occurrence->remindAt->greaterThan($after)) {
                $planned[] = $occurrence;
            }

            $cursor = $this->advance($schedule, $date);
        }

        return $planned;
    }

    /**
     * One occurrence, fully resolved: its nominal identity and its instant.
     */
    public function at(ReminderSchedule $schedule, string $localDate): PlannedOccurrence
    {
        $nominal = $localDate.' '.$schedule->local_time;
        $shifted = LocalInstant::isNonexistent($nominal, $schedule->timezone);

        return new PlannedOccurrence(
            localDate: $localDate,
            // The identity is the REQUESTED wall clock, shifted or not.
            key: $localDate.'T'.$schedule->local_time,
            localAt: $nominal.':00',
            remindAt: LocalInstant::resolve($nominal, $schedule->timezone),
            shifted: $shifted,
        );
    }

    /**
     * Where the walk starts: the later of the schedule's own start and today in
     * the subscriber's zone, so a series created for next month does not plan
     * from today and a long-dormant one does not plan from its start date.
     */
    private function firstLocalDate(ReminderSchedule $schedule, CarbonImmutable $after, string $zone): string
    {
        $today = $after->copy()->setTimezone($zone)->format('Y-m-d');
        $starts = $schedule->starts_on->format('Y-m-d');

        return max($today, $starts);
    }

    /**
     * The first date on or after $cursor that this pattern actually falls on.
     */
    private function matchingDate(ReminderSchedule $schedule, string $cursor): ?string
    {
        return match ($schedule->pattern) {
            ReminderPattern::Daily => $cursor,
            ReminderPattern::Weekly => $this->nextWeekday($schedule, $cursor),
            ReminderPattern::Monthly => $this->nextMonthDay($schedule, $cursor),
        };
    }

    /** The next date on or after $cursor whose ISO weekday is in the list. */
    private function nextWeekday(ReminderSchedule $schedule, string $cursor): ?string
    {
        $days = array_values(array_unique(array_map('intval', (array) ($schedule->weekdays ?? []))));

        if ($days === []) {
            return null;    // A weekly schedule with no weekdays plans nothing.
        }

        $date = new DateTimeImmutable($cursor, new DateTimeZone('UTC'));

        // At most seven steps: one week contains every weekday exactly once.
        for ($i = 0; $i < 7; $i++) {
            if (in_array((int) $date->format('N'), $days, true)) {
                return $date->format('Y-m-d');
            }

            $date = $date->modify('+1 day');
        }

        return null;
    }

    /**
     * The next occurrence date of a monthly series, CLAMPED to the month's last
     * valid day.
     *
     * Walking calendar months rather than adding 30 days, and clamping rather
     * than letting the date overflow into the following month — PHP's own
     * `+1 month` on 31 January lands on 3 March, which is neither what the
     * subscriber said nor stable from one year to the next.
     */
    private function nextMonthDay(ReminderSchedule $schedule, string $cursor): ?string
    {
        $requested = (int) $schedule->day_of_month;

        if ($requested < 1) {
            return null;
        }

        $month = new DateTimeImmutable((new DateTimeImmutable($cursor, new DateTimeZone('UTC')))->format('Y-m-01'), new DateTimeZone('UTC'));

        // This month, then the next: two candidates are always enough.
        for ($i = 0; $i < 2; $i++) {
            $candidate = $this->clamped($month, $requested);

            if ($candidate >= $cursor) {
                return $candidate;
            }

            $month = $month->modify('first day of next month');
        }

        return null;
    }

    /** The requested day of month, or the month's last day when it is shorter. */
    private function clamped(DateTimeImmutable $monthStart, int $requested): string
    {
        $lastDay = (int) $monthStart->format('t');

        return $monthStart->format('Y-m-').sprintf('%02d', min($requested, $lastDay));
    }

    /** Where the walk resumes after planning $date. */
    private function advance(ReminderSchedule $schedule, string $date): string
    {
        $day = new DateTimeImmutable($date, new DateTimeZone('UTC'));

        return match ($schedule->pattern) {
            // Daily and weekly resume the next day; weekly then skips forward to
            // its next declared weekday on its own.
            ReminderPattern::Daily, ReminderPattern::Weekly => $day->modify('+1 day')->format('Y-m-d'),
            // Monthly resumes at the first of the NEXT month, so a clamped
            // February does not re-match within the same month.
            ReminderPattern::Monthly => $day->modify('first day of next month')->format('Y-m-d'),
        };
    }
}
