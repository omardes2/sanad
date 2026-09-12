<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a follow-up is held in `blocked` — a CLOSED list, because an operational
 * hold that cannot be named cannot be cleared.
 *
 * A blocked follow-up produces no asks and spends no budget. That combination is
 * the whole point: a configuration gap must never drain a subscriber's follow-up
 * ladder, and it must never produce a stream of failed deliveries either.
 */
enum FollowUpBlockReason: string
{
    /**
     * No approved WhatsApp FOLLOW-UP template is configured, so an ask that falls
     * outside the service window has no permitted mechanism. Recovery is an
     * explicit, idempotent operator action once the template exists.
     */
    case TemplateUnavailable = 'template_unavailable';

    /**
     * DELIVERY COULD NOT BE ESTABLISHED. One value, because from the ladder's point
     * of view these are one fact — Sanad cannot prove the subscriber was asked:
     *
     *   - the channel cannot send at all, or there is no reachable account on it
     *     (a configuration or account gap, known before anything is attempted);
     *   - an ask ended terminally WITHOUT reaching `sent`: an unknown outcome whose
     *     attempt budget ran out, a rejection, or a moment that passed.
     *
     * The second case is deliberately NOT recorded as "sent" or as "not sent" —
     * both are claims Sanad cannot support. The loop is held and a person decides,
     * which is the only honest handling of an unknown outcome.
     */
    case DeliveryUnavailable = 'delivery_unavailable';

    public function label(): string
    {
        return match ($this) {
            self::TemplateUnavailable => 'لا يوجد قالب متابعة معتمَد ومضبوط',
            self::DeliveryUnavailable => 'لم يثبت وصول السؤال: القناة غير قادرة على الإرسال أو انتهى السؤال بلا حالة «أُرسل»',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
