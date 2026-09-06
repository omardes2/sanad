<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Support\Billing\DecimalMath;
use App\Support\Payments\MoneyRules;

/** Cents (possibly negative for a net figure) → "x.yy" without floats. */
final class MoneyFormat
{
    public static function of(int $cents): string
    {
        return $cents < 0 ? '-'.DecimalMath::format(-$cents, MoneyRules::SCALE) : DecimalMath::format($cents, MoneyRules::SCALE);
    }
}
