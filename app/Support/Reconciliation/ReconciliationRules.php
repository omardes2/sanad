<?php

declare(strict_types=1);

namespace App\Support\Reconciliation;

use App\Enums\CostComponent;
use App\Exceptions\Payments\PaymentRuleException;
use App\Exceptions\Reconciliation\ReconciliationRuleException;
use App\Support\Billing\DecimalMath;
use App\Support\Payments\MoneyRules;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Shared validation for Phase E2: ledger-scale (6) signed amounts parsed to
 * integers (no floats), ISO currencies, bounded reference tokens (reused from
 * E1: no whitespace, no e-mail, no card / account number shapes), and the
 * E2 period contract — one calendar month UTC, [first 00:00, next first 00:00).
 */
final class ReconciliationRules
{
    public const SCALE = 6;

    /** @return int scaled signed amount (may be negative; zero allowed only when $allowZero) */
    public static function signedAmount(string $amount, string $rule, bool $allowZero = false): int
    {
        $value = trim($amount);
        $negative = str_starts_with($value, '-');

        try {
            $scaled = DecimalMath::toScaled(ltrim($value, '+-'), self::SCALE);
        } catch (InvalidArgumentException) {
            throw ReconciliationRuleException::of($rule, 'المبلغ يجب أن يكون رقمًا عشريًا (حتى 6 منازل) بإشارة اختيارية.');
        }

        if ($scaled === 0 && ! $allowZero) {
            throw ReconciliationRuleException::of($rule, 'المبلغ لا يمكن أن يكون صفرًا.');
        }

        return $negative ? -$scaled : $scaled;
    }

    /** @return int scaled amount (> 0) */
    public static function positiveAmount(string $amount, string $rule): int
    {
        $scaled = self::signedAmount($amount, $rule);

        if ($scaled <= 0) {
            throw ReconciliationRuleException::of($rule, 'المبلغ يجب أن يكون أكبر من صفر.');
        }

        return $scaled;
    }

    public static function format(int $scaled): string
    {
        return $scaled < 0 ? '-'.DecimalMath::format(-$scaled, self::SCALE) : DecimalMath::format($scaled, self::SCALE);
    }

    public static function currency(string $currency, string $rule): string
    {
        try {
            return MoneyRules::currency($currency, $rule);
        } catch (PaymentRuleException $e) {
            throw ReconciliationRuleException::of($rule, $e->getMessage());
        }
    }

    public static function ref(?string $value, int $max, string $rule): ?string
    {
        try {
            return MoneyRules::boundedRef($value, $max, $rule);
        } catch (PaymentRuleException $e) {
            throw ReconciliationRuleException::of($rule, $e->getMessage());
        }
    }

    /** Same contract as MoneyRules::idempotencyKey (opaque, non-empty, ≤ 191, single line, no payload / PII), refused as an E2 rule. */
    public static function idempotencyKey(?string $key, string $rule = 'idempotency_key'): string
    {
        try {
            return MoneyRules::idempotencyKey($key, $rule);
        } catch (PaymentRuleException $e) {
            throw ReconciliationRuleException::of($rule, $e->getMessage());
        }
    }

    public static function requiredRef(?string $value, int $max, string $rule): string
    {
        $ref = self::ref($value, $max, $rule);

        if ($ref === null) {
            throw ReconciliationRuleException::of($rule, 'هذا المرجع إلزامي.');
        }

        return $ref;
    }

    public static function component(string $component): CostComponent
    {
        return CostComponent::tryFrom(strtolower(trim($component))) ?? throw ReconciliationRuleException::of('component', 'المكوّن يجب أن يكون provider أو communication أو external.');
    }

    /**
     * The E2 period contract: a calendar month given as "YYYY-MM" (UTC).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} [start, end)
     */
    public static function month(string $month): array
    {
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', trim($month), $m) !== 1) {
            throw ReconciliationRuleException::of('period', 'الفترة يجب أن تكون شهرًا تقويميًا بصيغة YYYY-MM (UTC).');
        }

        $start = CarbonImmutable::create((int) $m[1], (int) $m[2], 1, 0, 0, 0, 'UTC');

        return [$start, $start->addMonth()];
    }

    /** Assert an arbitrary [start, end) pair IS a calendar month UTC (defence for stored rows). */
    public static function assertCalendarMonth(CarbonImmutable $start, CarbonImmutable $end): void
    {
        $utcStart = $start->utc();

        if ($utcStart->day !== 1 || $utcStart->format('H:i:s.u') !== '00:00:00.000000' || ! $utcStart->addMonth()->equalTo($end->utc())) {
            throw ReconciliationRuleException::of('period', 'نطاق التسوية في E2 هو شهر تقويمي كامل بتوقيت UTC فقط.');
        }
    }

    public static function notInFuture(CarbonImmutable $at, string $rule): void
    {
        try {
            MoneyRules::notInFuture($at, $rule);
        } catch (PaymentRuleException $e) {
            throw ReconciliationRuleException::of($rule, $e->getMessage());
        }
    }
}
