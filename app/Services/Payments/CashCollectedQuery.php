<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Data\Payments\CashSummary;
use App\Enums\CustomerPaymentEventType;
use App\Models\CustomerPayment;
use App\Models\CustomerRefund;
use App\Models\PaymentAllocation;
use App\Models\RefundAllocation;
use App\Services\Finance\FinanceSql;
use App\Support\Billing\DecimalMath;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Cash figures (Phase E1), event-based and per currency — never revenue:
 *
 *  - Gross Cash Collected: Σ amount of payments that have a `succeeded`
 *    lifecycle event, by received_at in the window. A later status change
 *    (dispute) never removes it — the cash WAS collected then.
 *  - Refunds: Σ refunds by refunded_at in the window. Net Cash = gross − refunds.
 *  - Gateway Fees: Σ known fees of those payments; any NULL fee makes
 *    net-after-fees NULL ("FEES UNKNOWN"), never zero.
 *  - Attribution (period_start in window): Allocated Collected Amount,
 *    Refund Allocated Amount, Net Allocated Amount = the difference.
 *  - Unallocated Gross Collected Amount = gross (payments received in the
 *    window) − Σ their payment allocations (refunds never erase allocations).
 * All sums are scaled integers in SQL; no PHP floats. Window ≤ 366 days.
 */
final class CashCollectedQuery
{
    public const MAX_DAYS = 366;

    public function __construct(private readonly FinanceSql $sql) {}

    /**
     * @return array<string, CashSummary> keyed by currency, sorted
     */
    public function summarise(CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($to <= $from) {
            throw new InvalidArgumentException('نهاية النطاق يجب أن تكون بعد بدايته.');
        }

        if ($from->diffInDays($to) > self::MAX_DAYS) {
            throw new InvalidArgumentException('النطاق الأقصى '.self::MAX_DAYS.' يومًا.');
        }

        $this->sql->driver(); // refuse unsupported drivers loudly
        $fromValue = $from->format(CustomerPayment::TIMESTAMP_FORMAT);
        $toValue = $to->format(CustomerPayment::TIMESTAMP_FORMAT);
        $succeeded = CustomerPaymentEventType::Succeeded->value;

        $currencies = [];

        // Payments that succeeded, received in the window.
        $payments = CustomerPayment::query()->toBase()
            ->whereExists(static function ($q) use ($succeeded): void {
                $q->selectRaw('1')->from('customer_payment_events')
                    ->whereColumn('customer_payment_events.customer_payment_id', 'customer_payments.id')
                    ->where('customer_payment_events.event_type', $succeeded);
            })
            ->where('received_at', '>=', $fromValue)->where('received_at', '<', $toValue)
            ->selectRaw(implode(', ', [
                'currency',
                'COUNT(*) AS n',
                $this->scaled('amount').' AS gross',
                $this->scaledWhere('gateway_fee_amount', 'gateway_fee_amount IS NOT NULL').' AS fees_known',
                $this->sql->countWhere('gateway_fee_amount IS NULL').' AS fees_unknown',
            ]))
            ->groupBy('currency')
            ->get()->keyBy('currency');

        // The unallocated figure needs a per-payment subtraction, so compute it separately and exactly.
        $unallocated = [];
        foreach (CustomerPayment::query()->toBase()
            ->whereExists(static function ($q) use ($succeeded): void {
                $q->selectRaw('1')->from('customer_payment_events')
                    ->whereColumn('customer_payment_events.customer_payment_id', 'customer_payments.id')
                    ->where('customer_payment_events.event_type', $succeeded);
            })
            ->where('received_at', '>=', $fromValue)->where('received_at', '<', $toValue)
            ->selectRaw('customer_payments.id, customer_payments.currency, ROUND(customer_payments.amount * 100) AS amt, COALESCE((SELECT SUM(ROUND(pa.amount * 100)) FROM payment_allocations pa WHERE pa.customer_payment_id = customer_payments.id), 0) AS alloc')
            ->get() as $row) {
            $unallocated[$row->currency] = ($unallocated[$row->currency] ?? 0) + (DecimalMath::intFromDb($row->amt) - DecimalMath::intFromDb($row->alloc));
        }

        $refunds = CustomerRefund::query()->toBase()
            ->where('refunded_at', '>=', $fromValue)->where('refunded_at', '<', $toValue)
            ->selectRaw('currency, COUNT(*) AS n, '.$this->scaled('amount').' AS total')
            ->groupBy('currency')->get()->keyBy('currency');

        $allocations = PaymentAllocation::query()->toBase()
            ->where('period_start', '>=', $from->format('Y-m-d H:i:s'))->where('period_start', '<', $to->format('Y-m-d H:i:s'))
            ->selectRaw('currency, '.$this->scaled('amount').' AS total')
            ->groupBy('currency')->get()->keyBy('currency');

        $refundAllocations = RefundAllocation::query()->toBase()
            ->join('payment_allocations', 'payment_allocations.id', '=', 'refund_allocations.payment_allocation_id')
            ->where('payment_allocations.period_start', '>=', $from->format('Y-m-d H:i:s'))->where('payment_allocations.period_start', '<', $to->format('Y-m-d H:i:s'))
            ->selectRaw('refund_allocations.currency AS currency, '.$this->scaled('refund_allocations.amount').' AS total')
            ->groupBy('refund_allocations.currency')->get()->keyBy('currency');

        foreach ([$payments, $refunds, $allocations, $refundAllocations] as $set) {
            foreach ($set->keys() as $currency) {
                $currencies[(string) $currency] = true;
            }
        }

        ksort($currencies);
        $out = [];

        foreach (array_keys($currencies) as $currency) {
            $p = $payments->get($currency);
            $r = $refunds->get($currency);
            $a = $allocations->get($currency);
            $ra = $refundAllocations->get($currency);

            $gross = $p === null ? 0 : DecimalMath::intFromDb($p->gross);
            $refunded = $r === null ? 0 : DecimalMath::intFromDb($r->total);
            $feesKnown = $p === null ? 0 : DecimalMath::intFromDb($p->fees_known);
            $feesUnknown = $p === null ? 0 : DecimalMath::intFromDb($p->fees_unknown);
            $allocated = $a === null ? 0 : DecimalMath::intFromDb($a->total);
            $refundAllocated = $ra === null ? 0 : DecimalMath::intFromDb($ra->total);

            $out[$currency] = new CashSummary(
                currency: $currency,
                paymentsCount: $p === null ? 0 : DecimalMath::intFromDb($p->n),
                grossCashCollected: MoneyFormat::of($gross),
                refundsCount: $r === null ? 0 : DecimalMath::intFromDb($r->n),
                refunds: MoneyFormat::of($refunded),
                netCash: MoneyFormat::of($gross - $refunded),
                gatewayFeesKnown: MoneyFormat::of($feesKnown),
                feesUnknownCount: $feesUnknown,
                netCashAfterGatewayFees: $feesUnknown > 0 ? null : MoneyFormat::of($gross - $refunded - $feesKnown),
                allocatedCollectedAmount: MoneyFormat::of($allocated),
                refundAllocatedAmount: MoneyFormat::of($refundAllocated),
                netAllocatedAmount: MoneyFormat::of($allocated - $refundAllocated),
                unallocatedGrossCollectedAmount: MoneyFormat::of($unallocated[$currency] ?? 0),
            );
        }

        return $out;
    }

    /** Σ of a decimal(12,2) column as an integer number of cents (exact on both engines). */
    private function scaled(string $column): string
    {
        return match ($this->sql->driver()) {
            'pgsql' => "COALESCE(SUM(ROUND({$column} * 100)::bigint), 0)",
            'sqlite' => "COALESCE(SUM(CAST(ROUND({$column} * 100) AS INTEGER)), 0)",
        };
    }

    private function scaledWhere(string $column, string $where): string
    {
        $scaled = match ($this->sql->driver()) {
            'pgsql' => "ROUND({$column} * 100)::bigint",
            'sqlite' => "CAST(ROUND({$column} * 100) AS INTEGER)",
        };

        return "COALESCE(SUM(CASE WHEN {$where} THEN {$scaled} ELSE 0 END), 0)";
    }
}
