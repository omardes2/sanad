<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The recurrence patterns V1 supports — a CLOSED, deliberately small set.
 *
 * Each pattern answers exactly one question: which NOMINAL LOCAL DATES does
 * this series fall on? The time of day is a separate field, and turning a
 * nominal local date plus a time into an instant is a separate concern again
 * (App\Support\Reminders\OccurrencePlanner), because that step is where
 * timezones and DST live and it must not be duplicated per pattern.
 *
 * WHAT IS DELIBERATELY ABSENT, and why it is absent rather than forgotten:
 * cron expressions and RRULE (an unbounded expression language whose every
 * corner would need its own DST answer), "every N days" (an anchor-drift
 * problem that interacts badly with clamping), yearly (one more leap-day rule),
 * "last weekday of the month", several times per day, and anything shorter than
 * a day. Each is a product decision with its own horizon and duplicate-safety
 * consequences, and each would be a new case here plus a new planner branch —
 * never a new expression parser.
 */
enum ReminderPattern: string
{
    /** Every day at the schedule's local time. */
    case Daily = 'daily';

    /** On the declared weekdays, at the schedule's local time. */
    case Weekly = 'weekly';

    /** On the declared day of month — CLAMPED to the last valid day. */
    case Monthly = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'يوميًا',
            self::Weekly => 'أسبوعيًا',
            self::Monthly => 'شهريًا',
        };
    }

    /** Does this pattern require a non-empty weekday list? */
    public function needsWeekdays(): bool
    {
        return $this === self::Weekly;
    }

    /** Does this pattern require a day of month? */
    public function needsDayOfMonth(): bool
    {
        return $this === self::Monthly;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
