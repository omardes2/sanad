<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\CustomerRefund;
use App\Models\PaymentAllocation;
use App\Models\RefundAllocation;
use App\Support\Billing\DecimalMath;

/**
 * Display-only sums for the payment pages (Phase E5.2a) — the SAME scaled
 * integer sums the E1 services check their caps against (Σ refunds and
 * Σ allocations per payment, Σ refund allocations per refund and per payment
 * allocation), so "remaining" figures on screen never disagree with a refusal.
 * Reads only; never clips, never decides — the services stay the authority.
 */
final class PaymentLedgerView
{
    /**
     * @param  list<int>  $paymentIds
     * @return array{refunded: array<int, int>, allocated: array<int, int>} cents keyed by payment id
     */
    public function paymentSums(array $paymentIds): array
    {
        if ($paymentIds === []) {
            return ['refunded' => [], 'allocated' => []];
        }

        return [
            'refunded' => self::centsBy(CustomerRefund::query()->whereIn('customer_payment_id', $paymentIds)->toBase()->selectRaw('customer_payment_id AS k, COALESCE(SUM(ROUND(amount * 100)), 0) AS s')->groupBy('customer_payment_id')->get()),
            'allocated' => self::centsBy(PaymentAllocation::query()->whereIn('customer_payment_id', $paymentIds)->toBase()->selectRaw('customer_payment_id AS k, COALESCE(SUM(ROUND(amount * 100)), 0) AS s')->groupBy('customer_payment_id')->get()),
        ];
    }

    /**
     * @param  list<int>  $refundIds
     * @return array<int, int> cents attributed per refund
     */
    public function refundAllocated(array $refundIds): array
    {
        return $refundIds === [] ? [] : self::centsBy(RefundAllocation::query()->whereIn('customer_refund_id', $refundIds)->toBase()->selectRaw('customer_refund_id AS k, COALESCE(SUM(ROUND(amount * 100)), 0) AS s')->groupBy('customer_refund_id')->get());
    }

    /**
     * @param  list<int>  $allocationIds
     * @return array<int, int> cents reversed per payment allocation
     */
    public function allocationReversed(array $allocationIds): array
    {
        return $allocationIds === [] ? [] : self::centsBy(RefundAllocation::query()->whereIn('payment_allocation_id', $allocationIds)->toBase()->selectRaw('payment_allocation_id AS k, COALESCE(SUM(ROUND(amount * 100)), 0) AS s')->groupBy('payment_allocation_id')->get());
    }

    public static function cents(string $amount): int
    {
        return DecimalMath::toScaled($amount, 2);
    }

    public static function money(int $cents): string
    {
        return MoneyFormat::of($cents);
    }

    /**
     * @param  iterable<object{k: mixed, s: mixed}>  $rows
     * @return array<int, int>
     */
    private static function centsBy(iterable $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->k] = DecimalMath::intFromDb($row->s);
        }

        return $out;
    }
}
