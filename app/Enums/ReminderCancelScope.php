<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a subscriber means when they cancel a recurring series.
 *
 * Deliberately only TWO scopes. Cancelling ONE occurrence is not here, because
 * an occurrence is an ordinary reminder and `reminder.cancel@1` already cancels
 * one by `reminder_id`. Keeping it out is what keeps the two object identities
 * clean: a `reminder_id` names an occurrence, a `schedule_id` names a series,
 * and no tool can blur them.
 *
 * The two scopes differ in exactly one row: an occurrence whose time has come
 * but which no worker has claimed yet. That difference is kept rather than
 * collapsed because the subscriber's two sentences are genuinely different —
 * «بطّل التذكير من بكرا» and «بطّل التذكير خلص» — and silently treating the
 * first as the second cancels something they expected to still arrive.
 *
 * Neither scope ever touches an occurrence that is being delivered right now,
 * or one already sent or failed: a delivery in flight is not a schedule's to
 * retract, and rewriting a settled row would erase its record.
 */
enum ReminderCancelScope: string
{
    /**
     * Stop the series, and cancel pending occurrences STRICTLY AFTER NOW. An
     * occurrence already due keeps its chance to arrive.
     */
    case Future = 'future';

    /**
     * Stop the series, and cancel EVERY pending occurrence — including one that
     * is due now and not yet claimed.
     */
    case Series = 'series';

    public function label(): string
    {
        return match ($this) {
            self::Future => 'المرّات القادمة',
            self::Series => 'السلسلة كاملة',
        };
    }

    /** Does this scope also cancel an occurrence that is already due? */
    public function includesDue(): bool
    {
        return $this === self::Series;
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
