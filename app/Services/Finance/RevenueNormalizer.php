<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\BillingPeriod;
use App\Support\Billing\DecimalMath;

/**
 * Normalises a plan price to its MONTHLY equivalent, in exact fixed-point
 * arithmetic (no floats), rounded HALF UP once to the ledger scale (6):
 *
 *   monthly → price · yearly → price ÷ 12 · weekly → price × 52 ÷ 12
 *   daily → price × 365 ÷ 12 · none → 0 (non-recurring: not MRR)
 *
 * This is the Calculated figure behind MRR — a theoretical, list-price number,
 * never cash collected.
 */
final class RevenueNormalizer
{
    public const SCALE = FinanceSql::LEDGER_SCALE;

    /** Intermediate precision before the single final rounding. */
    private const WORK_SCALE = 8;

    public static function monthly(string $price, BillingPeriod $period): string
    {
        $scaled = DecimalMath::toScaled($price, self::WORK_SCALE);

        $monthly = match ($period) {
            BillingPeriod::Monthly => $scaled,
            BillingPeriod::Yearly => DecimalMath::mulDiv($scaled, 1, 12),
            BillingPeriod::Weekly => DecimalMath::mulDiv($scaled, 52, 12),
            BillingPeriod::Daily => DecimalMath::mulDiv($scaled, 365, 12),
            BillingPeriod::None => 0,
        };

        return DecimalMath::format(DecimalMath::rescale($monthly, self::WORK_SCALE, self::SCALE), self::SCALE);
    }

    /** monthly-equivalent price × a subscription count, at scale 6. */
    public static function times(string $monthly, int $count): string
    {
        $scaled = DecimalMath::toScaled($monthly, self::SCALE);

        return DecimalMath::format(DecimalMath::mulDiv($scaled, max(0, $count), 1), self::SCALE);
    }
}
