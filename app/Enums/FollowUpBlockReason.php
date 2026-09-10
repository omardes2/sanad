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
     * The channel cannot send at all, or the subscriber has no reachable account
     * on it. Also a configuration/account fact rather than a delivery failure.
     */
    case DeliveryUnavailable = 'delivery_unavailable';

    public function label(): string
    {
        return match ($this) {
            self::TemplateUnavailable => 'لا يوجد قالب متابعة معتمَد ومضبوط',
            self::DeliveryUnavailable => 'القناة غير قادرة على الإرسال للمشترك',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
