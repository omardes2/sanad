<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\CarbonImmutable;

/**
 * How often a plan renews. "None" is a non-expiring plan (e.g. a free tier).
 */
enum BillingPeriod: string
{
    case None = 'none';
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    /**
     * The end of a billing period starting at $start, or null for None.
     */
    public function endFrom(CarbonImmutable $start): ?CarbonImmutable
    {
        return match ($this) {
            self::None => null,
            self::Daily => $start->addDay(),
            self::Weekly => $start->addWeek(),
            self::Monthly => $start->addMonth(),
            self::Yearly => $start->addYear(),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::None => 'بدون تجديد',
            self::Daily => 'يومي',
            self::Weekly => 'أسبوعي',
            self::Monthly => 'شهري',
            self::Yearly => 'سنوي',
        };
    }
}
