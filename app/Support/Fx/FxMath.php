<?php

declare(strict_types=1);

namespace App\Support\Fx;

use App\Enums\FxDirection;
use App\Exceptions\Fx\FxRuleException;
use App\Support\Billing\DecimalMath;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

/**
 * Exact FX arithmetic (Phase E3) on big decimals — no floats, no bcmath
 * dependency (brick/math ships with the framework):
 *
 *   direct  (BASE → QUOTE):  target = source × rate
 *   inverse (QUOTE → BASE):  target = source ÷ rate     (same rate row, no reciprocal)
 *
 * The rate carries 12 decimals; the source keeps its own scale; the result is
 * rounded exactly ONCE, half-up, at the target scale. No intermediate
 * rounding, no reciprocal rounding, no third currency.
 */
final class FxMath
{
    public const RATE_SCALE = 12;

    /** @return int the rate as a scaled integer (scale 12), > 0 */
    public static function rateToScaled(string $rate): int
    {
        try {
            $scaled = DecimalMath::toScaled(trim($rate), self::RATE_SCALE);
        } catch (\InvalidArgumentException) {
            throw FxRuleException::of('rate', 'سعر الصرف يجب أن يكون رقمًا عشريًا موجبًا (حتى 12 منزلة).');
        }

        if ($scaled <= 0) {
            throw FxRuleException::of('rate', 'سعر الصرف يجب أن يكون أكبر من صفر.');
        }

        return $scaled;
    }

    /**
     * Convert a decimal amount string. Returns the target amount as a decimal
     * string at $targetScale, rounded half-up once.
     */
    public static function convert(string $sourceAmount, int $sourceScale, string $rate, FxDirection $direction, int $targetScale): string
    {
        try {
            $source = BigDecimal::of(trim($sourceAmount))->toScale($sourceScale, RoundingMode::Unnecessary);
            $fx = BigDecimal::of(trim($rate))->toScale(self::RATE_SCALE, RoundingMode::Unnecessary);
        } catch (MathException) {
            throw FxRuleException::of('amount', 'المبلغ أو السعر لا يطابق المقياس المطلوب بلا تقريب.');
        }

        if ($fx->isNegativeOrZero()) {
            throw FxRuleException::of('rate', 'سعر الصرف يجب أن يكون أكبر من صفر.');
        }

        return match ($direction) {
            FxDirection::Direct => $source->multipliedBy($fx)->toScale($targetScale, RoundingMode::HalfUp)->__toString(),
            // Exact rational division, rounded once at the target scale.
            FxDirection::Inverse => $source->dividedBy($fx, $targetScale, RoundingMode::HalfUp)->__toString(),
        };
    }

    /** Re-express a stored decimal(…,6) value at the scale it was frozen with (exact, no rounding). */
    public static function formatAtScale(string $value, int $scale): string
    {
        return BigDecimal::of($value)->toScale($scale, RoundingMode::Unnecessary)->__toString();
    }

    /** Which direction converts $from into $to using a pair quoted base→quote; null if the pair does not cover them. */
    public static function directionFor(string $base, string $quote, string $from, string $to): ?FxDirection
    {
        if ($from === $base && $to === $quote) {
            return FxDirection::Direct;
        }

        if ($from === $quote && $to === $base) {
            return FxDirection::Inverse;
        }

        return null;
    }
}
