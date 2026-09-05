<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a subscriber's subscription. Payment providers (later) drive
 * transitions between these; the domain never assumes a specific gateway.
 */
enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Suspended = 'suspended';

    /**
     * Whether a subscription in this state may consume metered capabilities.
     */
    public function isEntitled(): bool
    {
        return $this === self::Trialing || $this === self::Active;
    }

    public function label(): string
    {
        return match ($this) {
            self::Trialing => 'تجريبي',
            self::Active => 'نشِط',
            self::PastDue => 'متأخّر السداد',
            self::Expired => 'منتهٍ',
            self::Cancelled => 'ملغى',
            self::Suspended => 'موقوف',
        };
    }
}
