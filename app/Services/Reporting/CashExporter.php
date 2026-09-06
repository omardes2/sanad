<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Data\Fx\ReportingLine;
use App\Enums\CustomerPaymentEventType;
use App\Models\CustomerPayment;
use App\Models\CustomerRefund;
use App\Models\PaymentAllocation;
use App\Models\RefundAllocation;
use App\Services\Fx\ReportingView;
use App\Services\Payments\CashCollectedQuery;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cash CSV (Phase E5.1) — LIVE / CURRENT, event-based, read-only. Built on
 * CashCollectedQuery (per-currency figures) and ReportingView::cash (the
 * NATIVE / CONVERTED / NOT CONVERTED status of every payment and refund with
 * the frozen conversion facts), i.e. the same services as the pages.
 * Sections: meta · cash_summary · reporting_totals · payments · refunds ·
 * payment_allocations · refund_allocations. Unknown fee = empty cell +
 * `FEES UNKNOWN`; an incomplete reporting total = empty cell + status.
 * Ids and bounded references only.
 */
final class CashExporter
{
    public function __construct(private readonly CashCollectedQuery $cash, private readonly ReportingView $reporting) {}

    public function stream(CarbonImmutable $from, CarbonImmutable $to): StreamedResponse
    {
        $filename = sprintf('sanad-finance-cash-%s-%s.csv', $from->format('Ymd'), $to->subDay()->format('Ymd'));

        return CsvWriter::download($filename, function (CsvWriter $w) use ($from, $to): void {
            $summary = $this->cash->summarise($from, $to);
            $view = $this->reporting->cash($from, $to);
            $lines = [];
            foreach ($view['lines'] as $line) {
                $lines[$line->subjectType.':'.$line->subjectId] = $line;
            }

            $w->meta([
                'basis' => 'LIVE / CURRENT',
                'vocabulary' => 'cash (event-based: succeeded payments by received_at, refunds by refunded_at) — not revenue, not calculated cost',
                'window_from' => $from->toDateString(),
                'window_to' => $to->subDay()->toDateString(),
                'reporting_currency' => $view['currency'],
                'unknown_semantics' => 'empty numeric cell + status column; never 0',
            ]);

            $w->section('cash_summary', ['currency', 'payments', 'gross_cash_collected', 'refunds_count', 'refunds', 'net_cash', 'gateway_fees_known', 'fees_unknown_count', 'fees_status', 'net_cash_after_gateway_fees', 'net_cash_after_gateway_fees_status', 'allocated_collected_amount', 'refund_allocated_amount', 'net_allocated_amount', 'unallocated_gross_collected_amount']);
            foreach ($summary as $s) {
                $w->row('cash_summary', [$s->currency, $s->paymentsCount, $s->grossCashCollected, $s->refundsCount, $s->refunds, $s->netCash, $s->gatewayFeesKnown, $s->feesUnknownCount, $s->feesStatus(), $s->netCashAfterGatewayFees, $s->netCashAfterGatewayFees === null ? 'NOT AVAILABLE (FEES UNKNOWN)' : 'complete', $s->allocatedCollectedAmount, $s->refundAllocatedAmount, $s->netAllocatedAmount, $s->unallocatedGrossCollectedAmount]);
            }

            $w->section('reporting_totals', ['figure', 'reporting_currency', 'amount', 'status', 'lines', 'native', 'converted', 'not_converted']);
            foreach ($view['totals'] as $total) {
                $w->row('reporting_totals', [$total->label, $total->targetCurrency, $total->amount, $total->status(), $total->lines, $total->native, $total->converted, $total->notConverted]);
            }

            $fromValue = $from->format(CustomerPayment::TIMESTAMP_FORMAT);
            $toValue = $to->format(CustomerPayment::TIMESTAMP_FORMAT);
            $succeeded = CustomerPaymentEventType::Succeeded->value;

            $w->section('payments', ['payment_id', 'subscriber_id', 'gateway', 'gateway_payment_ref', 'received_at_utc', 'amount', 'currency', 'gateway_fee_amount', 'fee_status', 'fee_currency', 'current_status', 'reference', 'reason_code', 'evidence_ref', ...self::REPORTING_COLUMNS]);
            CustomerPayment::query()
                ->whereExists(static function ($q) use ($succeeded): void {
                    $q->selectRaw('1')->from('customer_payment_events')->whereColumn('customer_payment_events.customer_payment_id', 'customer_payments.id')->where('customer_payment_events.event_type', $succeeded);
                })
                ->where('received_at', '>=', $fromValue)->where('received_at', '<', $toValue)
                ->chunkById(500, function ($payments) use ($w, $lines, $view): void {
                    foreach ($payments as $p) {
                        $w->row('payments', [$p->id, $p->subscriber_id, $p->gateway, $p->gateway_payment_ref, CsvWriter::utc($p->received_at), (string) $p->amount, $p->currency, $p->gateway_fee_amount === null ? null : (string) $p->gateway_fee_amount, $p->gateway_fee_amount === null ? 'FEES UNKNOWN' : 'known', $p->fee_currency, $p->current_status->value, $p->reference, $p->reason_code, $p->evidence_ref, ...self::reporting($lines['customer_payment:'.$p->id] ?? null, $view['currency'])]);
                    }
                    $w->flush();
                });

            $w->section('refunds', ['refund_id', 'payment_id', 'gateway', 'gateway_refund_ref', 'refunded_at_utc', 'amount', 'currency', 'reason_code', 'evidence_ref', ...self::REPORTING_COLUMNS]);
            CustomerRefund::query()->where('refunded_at', '>=', $fromValue)->where('refunded_at', '<', $toValue)
                ->chunkById(500, function ($refunds) use ($w, $lines, $view): void {
                    foreach ($refunds as $r) {
                        $w->row('refunds', [$r->id, $r->customer_payment_id, $r->gateway, $r->gateway_refund_ref, CsvWriter::utc($r->refunded_at), (string) $r->amount, $r->currency, $r->reason_code, $r->evidence_ref, ...self::reporting($lines['customer_refund:'.$r->id] ?? null, $view['currency'])]);
                    }
                    $w->flush();
                });

            $w->section('payment_allocations', ['allocation_id', 'payment_id', 'subscription_id', 'subscription_event_id', 'subscriber_id', 'period_start_utc', 'period_end_utc', 'amount', 'currency', 'allocated_at_utc', 'reason_code']);
            PaymentAllocation::query()->where('period_start', '>=', $from->format('Y-m-d H:i:s'))->where('period_start', '<', $to->format('Y-m-d H:i:s'))
                ->chunkById(500, function ($allocations) use ($w): void {
                    foreach ($allocations as $a) {
                        $w->row('payment_allocations', [$a->id, $a->customer_payment_id, $a->subscription_id, $a->subscription_event_id, $a->subscriber_id, CsvWriter::utc($a->period_start), CsvWriter::utc($a->period_end), (string) $a->amount, $a->currency, CsvWriter::utc($a->allocated_at), $a->reason_code]);
                    }
                    $w->flush();
                });

            $w->section('refund_allocations', ['refund_allocation_id', 'refund_id', 'payment_allocation_id', 'amount', 'currency', 'allocated_at_utc', 'reason_code']);
            RefundAllocation::query()->select('refund_allocations.*')
                ->join('payment_allocations', 'payment_allocations.id', '=', 'refund_allocations.payment_allocation_id')
                ->where('payment_allocations.period_start', '>=', $from->format('Y-m-d H:i:s'))->where('payment_allocations.period_start', '<', $to->format('Y-m-d H:i:s'))
                ->chunkById(500, function ($rows) use ($w): void {
                    foreach ($rows as $ra) {
                        $w->row('refund_allocations', [$ra->id, $ra->customer_refund_id, $ra->payment_allocation_id, (string) $ra->amount, $ra->currency, CsvWriter::utc($ra->allocated_at), $ra->reason_code]);
                    }
                    $w->flush();
                }, 'refund_allocations.id', 'id');
        });
    }

    public const REPORTING_COLUMNS = ['reporting_status', 'reporting_amount', 'reporting_currency', 'fx_conversion_id', 'fx_rate_id', 'fx_rate_date', 'fx_rate_snapshot', 'fx_direction'];

    /**
     * @return list<string|int|null>
     */
    public static function reporting(?ReportingLine $line, ?string $target = null): array
    {
        if ($line === null) {
            return ['NOT CONVERTED', null, $target, null, null, null, null, null];
        }

        return [$line->status, $line->reportingAmount(), $target, $line->conversionId, $line->fxRateId, $line->fxRateDate, $line->rateSnapshot, $line->direction];
    }
}
