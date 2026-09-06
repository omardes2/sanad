<?php

declare(strict_types=1);

namespace App\Support\Payments;

use App\Exceptions\Payments\PaymentRuleException;
use App\Support\Billing\DecimalMath;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Shared validation for the Phase E1 money rules — every amount is a
 * non-negative decimal string parsed to a scaled integer (no floats), every
 * currency is a 3-letter ISO code, currencies never mix, and timestamps are
 * never in the future.
 */
final class MoneyRules
{
    public const SCALE = 2;

    /** Tolerance for clock skew between the admin's machine and the server. */
    public const FUTURE_TOLERANCE_SECONDS = 300;

    /** @return int scaled amount (> 0) */
    public static function positiveAmount(string $amount, string $rule): int
    {
        try {
            $scaled = DecimalMath::toScaled(trim($amount), self::SCALE);
        } catch (InvalidArgumentException $e) {
            throw PaymentRuleException::of($rule, 'المبلغ يجب أن يكون رقمًا عشريًا موجبًا بمنزلتين كحدّ أقصى.');
        }

        if ($scaled <= 0) {
            throw PaymentRuleException::of($rule, 'المبلغ يجب أن يكون أكبر من صفر.');
        }

        return $scaled;
    }

    /** @return int scaled amount (>= 0) */
    public static function nonNegativeAmount(string $amount, string $rule): int
    {
        try {
            return DecimalMath::toScaled(trim($amount), self::SCALE);
        } catch (InvalidArgumentException) {
            throw PaymentRuleException::of($rule, 'المبلغ يجب أن يكون رقمًا عشريًا غير سالب بمنزلتين كحدّ أقصى.');
        }
    }

    public static function currency(string $currency, string $rule): string
    {
        $code = strtoupper(trim($currency));

        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            throw PaymentRuleException::of($rule, 'العملة يجب أن تكون رمز ISO 4217 من ثلاثة أحرف.');
        }

        return $code;
    }

    public static function sameCurrency(string $expected, string $actual, string $rule): void
    {
        if (strtoupper($expected) !== strtoupper($actual)) {
            throw PaymentRuleException::of($rule, "العملات لا تُخلط: المتوقع {$expected} والمُدخل {$actual} (لا FX قبل E3).");
        }
    }

    public static function notInFuture(CarbonImmutable $at, string $rule): void
    {
        if ($at->greaterThan(CarbonImmutable::now()->addSeconds(self::FUTURE_TOLERANCE_SECONDS))) {
            throw PaymentRuleException::of($rule, 'الطابع الزمني في المستقبل غير مقبول.');
        }
    }

    public static function format(int $scaled): string
    {
        return DecimalMath::format($scaled, self::SCALE);
    }

    public static function boundedRef(?string $value, int $max, string $rule): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $max || preg_match('/^[\p{L}\p{N} _\-.:\/#@]+$/u', $value) !== 1) {
            throw PaymentRuleException::of($rule, "القيمة يجب ألا تتجاوز {$max} حرفًا وتقتصر على حروف/أرقام ورموز مرجعية بسيطة.");
        }

        return $value;
    }
}
