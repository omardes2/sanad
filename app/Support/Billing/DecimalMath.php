<?php

declare(strict_types=1);

namespace App\Support\Billing;

use InvalidArgumentException;

/**
 * Exact fixed-point arithmetic on decimal STRINGS using native integers, so
 * cost calculation is deterministic and does not depend on the bcmath
 * extension. Values are scaled integers: "0.15" at scale 8 is 15000000.
 *
 * Range is comfortably inside int64 for pricing: tokens (≤ 10^8) × a per-million
 * rate at scale 8 (≤ 10^13) stays below 9.2 × 10^18.
 */
final class DecimalMath
{
    /**
     * Parse a non-negative decimal string into an integer at the given scale.
     * Rejects more fractional digits than the scale (no silent rounding).
     */
    public static function toScaled(string $decimal, int $scale): int
    {
        $decimal = trim($decimal);

        if ($decimal === '' || preg_match('/^\+?(\d+)(?:\.(\d+))?$/', $decimal, $m) !== 1) {
            throw new InvalidArgumentException("Invalid non-negative decimal [{$decimal}].");
        }

        $integer = ltrim($m[1], '0');
        $fraction = $m[2] ?? '';

        if (strlen($fraction) > $scale) {
            throw new InvalidArgumentException("Decimal [{$decimal}] has more than {$scale} fractional digits.");
        }

        $digits = ($integer === '' ? '0' : $integer).str_pad($fraction, $scale, '0');
        $digits = ltrim($digits, '0');

        if ($digits === '') {
            return 0;
        }

        if (strlen($digits) > 18) {
            throw new InvalidArgumentException("Decimal [{$decimal}] is out of range at scale {$scale}.");
        }

        return (int) $digits;
    }

    /**
     * Format a scaled integer as a decimal string with exactly $scale digits.
     */
    public static function format(int $scaled, int $scale): string
    {
        if ($scaled < 0) {
            throw new InvalidArgumentException('Negative amounts are not supported.');
        }

        if ($scale === 0) {
            return (string) $scaled;
        }

        $digits = str_pad((string) $scaled, $scale + 1, '0', STR_PAD_LEFT);

        return substr($digits, 0, -$scale).'.'.substr($digits, -$scale);
    }

    /**
     * Reduce precision with ROUND HALF UP (the accounting convention).
     */
    public static function rescale(int $scaled, int $fromScale, int $toScale): int
    {
        if ($toScale >= $fromScale) {
            return $scaled * (10 ** ($toScale - $fromScale));
        }

        $divisor = 10 ** ($fromScale - $toScale);
        $quotient = intdiv($scaled, $divisor);
        $remainder = $scaled % $divisor;

        return $remainder * 2 >= $divisor ? $quotient + 1 : $quotient;
    }

    /**
     * scaled × $multiplier ÷ $divisor with ROUND HALF UP, entirely in integers.
     * Used to normalise a price to a monthly figure (× 52 ÷ 12, × 365 ÷ 12, ÷ 12).
     */
    public static function mulDiv(int $scaled, int $multiplier, int $divisor): int
    {
        if ($divisor <= 0 || $multiplier < 0 || $scaled < 0) {
            throw new InvalidArgumentException('mulDiv expects a non-negative amount and multiplier and a positive divisor.');
        }

        if ($multiplier !== 0 && $scaled > intdiv(PHP_INT_MAX, $multiplier)) {
            throw new InvalidArgumentException('mulDiv overflow.');
        }

        $product = $scaled * $multiplier;

        $quotient = intdiv($product, $divisor);
        $remainder = $product % $divisor;

        return $remainder * 2 >= $divisor ? $quotient + 1 : $quotient;
    }

    /**
     * Parse a database numeric value (PostgreSQL returns numeric/bigint as a
     * string, SQLite as int) into an integer WITHOUT going through a float.
     * Accepts only an integer literal; anything else is a programming error.
     */
    public static function intFromDb(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if ($value === null) {
            return 0;
        }

        $text = trim((string) $value);

        if (preg_match('/^-?\d+$/', $text) !== 1) {
            throw new InvalidArgumentException("Expected an integer from the database, got [{$text}].");
        }

        return (int) $text;
    }
}
