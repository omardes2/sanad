<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The life of a recurrence DEFINITION — two states, and only two.
 *
 * A schedule is not a delivery and has no delivery states: `sent`, `failed` and
 * the attempt budget belong to the OCCURRENCE rows, each of which is an
 * ordinary reminder with its own identity. Giving a schedule delivery states
 * would be the first step towards reusing one row for many sends, which is
 * exactly what this design refuses.
 *
 * `Terminated` is one-way and permanent. There is no "paused", and no edit:
 * changing a recurrence is terminating this one and creating another, so a
 * subscriber's history always shows which definition produced which occurrence.
 */
enum ReminderScheduleStatus: string
{
    /** Materialisation may create occurrences for it. */
    case Active = 'active';

    /**
     * Finished for good — by the subscriber cancelling it, or by passing its
     * end date. Materialisation must never create another occurrence for it,
     * and nothing reopens it.
     */
    case Terminated = 'terminated';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'فعّال',
            self::Terminated => 'منتهٍ',
        };
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
