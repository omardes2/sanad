<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The state of ONE open loop Sanad is following up on (Phase H3).
 *
 * A reminder is finished when it is delivered. A follow-up is finished when the
 * LOOP closes — which is a different question and the reason this enum exists
 * rather than a boolean on `reminders`.
 *
 * ── WHAT EACH STATE MEANS ────────────────────────────────────────────────────
 *   open             nothing is outstanding; an ask becomes due at `next_ask_at`
 *   awaiting_answer  an ask genuinely left the platform and no answer has come
 *   blocked          an OPERATIONAL hold (the approved template is unavailable).
 *                    Not success, not failure, and NOT a spent ask
 *   resolved_*       the loop closed because the subscriber said so, or because
 *                    the task it was attached to was completed
 *   abandoned        the ask budget ran out. Terminal and SILENT — Sanad stops
 *                    asking; it never escalates
 *   cancelled        the subscriber stopped it
 *
 * ── WHAT IS DELIBERATELY ABSENT ──────────────────────────────────────────────
 * There is no `expired`, because V1 has no deadline concept: the bounded ask
 * budget is the only stopping rule, and a state nothing can enter would be a
 * promise the schema does not keep.
 *
 * There is no state meaning "probably done". Silence never resolves a follow-up,
 * so the only paths out are an answer, a completion, a cancellation, or the
 * budget running out.
 */
enum FollowUpStatus: string
{
    case Open = 'open';
    case AwaitingAnswer = 'awaiting_answer';
    case Blocked = 'blocked';
    case ResolvedConfirmed = 'resolved_confirmed';
    case ResolvedByTask = 'resolved_by_task';
    case Abandoned = 'abandoned';
    case Cancelled = 'cancelled';

    /** Is the loop still Sanad's business — i.e. could it still ask or be answered? */
    public function isLive(): bool
    {
        return match ($this) {
            self::Open, self::AwaitingAnswer, self::Blocked => true,
            default => false,
        };
    }

    /** Terminal states: nothing moves a follow-up out of one of these. */
    public function isTerminal(): bool
    {
        return ! $this->isLive();
    }

    /** Did the loop close because it was actually resolved? */
    public function isResolved(): bool
    {
        return $this === self::ResolvedConfirmed || $this === self::ResolvedByTask;
    }

    public function label(): string
    {
        return match ($this) {
            self::Open => 'مفتوحة',
            self::AwaitingAnswer => 'بانتظار الجواب',
            self::Blocked => 'موقوفة تشغيليًا',
            self::ResolvedConfirmed => 'أكّد المشترك إنجازها',
            self::ResolvedByTask => 'أُنجزت مع المهمة',
            self::Abandoned => 'توقّفت المتابعة',
            self::Cancelled => 'ألغاها المشترك',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /** @return list<string> the states a follow-up can still act from */
    public static function liveValues(): array
    {
        return array_values(array_map(
            static fn (self $c): string => $c->value,
            array_filter(self::cases(), static fn (self $c): bool => $c->isLive()),
        ));
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
