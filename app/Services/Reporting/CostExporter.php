<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Models\CostAdjustment;
use App\Models\CostInvoice;
use App\Models\CostInvoiceAllocation;
use App\Models\CostInvoiceLine;
use App\Services\Fx\ReportingView;
use App\Services\Reconciliation\ReconciledCostQuery;
use App\Support\Reconciliation\ReconciliationRules;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cost / reconciliation CSV (Phase E5.1) — LIVE / CURRENT projections,
 * read-only. Built on ReconciledCostQuery (the current reconciliation of
 * every scope in the month range, coverage, variance-or-UNKNOWN, LEDGER MOVED
 * and EVIDENCE flags) and ReportingView::cost (reporting-currency status of
 * every base reconciled amount), plus the evidence rows (invoices, lines,
 * allocations) and adjustments. Sections: meta · scopes · reporting ·
 * invoices · invoice_lines · evidence_allocations · adjustments.
 * UNKNOWN variance = empty cell + variance_status; CONFIRMED ZERO stays a
 * status. Ids and bounded references only.
 */
final class CostExporter
{
    public function __construct(private readonly ReconciledCostQuery $costs, private readonly ReportingView $reporting) {}

    public function stream(string $fromMonth, string $toMonth): StreamedResponse
    {
        [$from] = ReconciliationRules::month($fromMonth);
        [, $to] = ReconciliationRules::month($toMonth);

        if ($to <= $from || $from->diffInMonths($to) > ReconciledCostQuery::MAX_MONTHS) {
            throw new InvalidArgumentException('نطاق الأشهر غير صالح (حتى '.ReconciledCostQuery::MAX_MONTHS.' شهرًا).'); // before any byte is streamed
        }

        $filename = sprintf('sanad-finance-cost-%s-%s.csv', $from->format('Ym'), $to->subMonth()->format('Ym'));

        return CsvWriter::download($filename, function (CsvWriter $w) use ($fromMonth, $toMonth, $from, $to): void {
            $scopes = $this->costs->summarise($fromMonth, $toMonth);
            $view = $this->reporting->cost($fromMonth, $toMonth);

            $w->meta([
                'basis' => 'LIVE / CURRENT',
                'vocabulary' => 'reconciled cost (current reconciliation per scope, evidence-backed) — not calculated cost, not cash, not revenue',
                'month_from' => $from->format('Y-m'),
                'month_to' => $to->subMonth()->format('Y-m'),
                'reporting_currency' => $view['currency'],
                'unknown_semantics' => 'empty numeric cell + status column; never 0; CONFIRMED ZERO is a typed certification, not a bare 0',
            ]);

            $w->section('scopes', ['scope_id', 'component', 'counterparty_key', 'month', 'currency', 'reconciliation_id', 'source', 'status', 'base_reconciled_amount', 'adjustments', 'adjusted_reconciled_cost', 'calculated_known_amount', 'calculated_priced_rows', 'unpriced_rows', 'currency_mismatch_rows', 'coverage', 'variance_vs_known_calculated', 'adjusted_variance_vs_known_calculated', 'variance_status', 'ledger_moved', 'flags']);
            foreach ($scopes as $s) {
                $w->row('scopes', [$s->scopeId, $s->component, $s->counterpartyKey, $s->month, $s->currency, $s->reconciliationId, $s->source, $s->status, $s->baseReconciledAmount, $s->adjustments, $s->adjustedReconciledCost, $s->calculatedKnownAmount, $s->calculatedPricedRows, $s->unpricedRows, $s->currencyMismatchRows, $s->coverage, $s->varianceVsKnownCalculated, $s->adjustedVarianceVsKnownCalculated, $s->varianceStatus, $s->ledgerMoved ? 'true' : 'false', implode(' | ', $s->flags)]);
            }

            $w->section('reporting', ['reconciliation_id', 'policy_date', 'source_amount', 'source_currency', ...CashExporter::REPORTING_COLUMNS]);
            foreach ($view['lines'] as $line) {
                $w->row('reporting', [$line->subjectId, $line->subjectDate, $line->sourceAmount, $line->sourceCurrency, ...CashExporter::reporting($line, $view['currency'])]);
            }
            $total = $view['totals']['base'];
            $w->section('reporting_totals', ['figure', 'reporting_currency', 'amount', 'status', 'lines', 'native', 'converted', 'not_converted']);
            $w->row('reporting_totals', [$total->label, $total->targetCurrency, $total->amount, $total->status(), $total->lines, $total->native, $total->converted, $total->notConverted]);

            $fromValue = $from->format('Y-m-d H:i:s');
            $toValue = $to->format('Y-m-d H:i:s');

            $w->section('invoices', ['invoice_id', 'component', 'counterparty_key', 'invoice_ref', 'issued_at_utc', 'period_start_utc', 'period_end_utc', 'currency', 'total_amount', 'current_status', 'superseded_by_id', 'evidence_ref']);
            CostInvoice::query()->where('period_start', '>=', $fromValue)->where('period_start', '<', $toValue)
                ->chunkById(500, function ($invoices) use ($w): void {
                    foreach ($invoices as $i) {
                        $w->row('invoices', [$i->id, $i->component->value, $i->counterparty_key, $i->invoice_ref, CsvWriter::utc($i->issued_at), CsvWriter::utc($i->period_start), CsvWriter::utc($i->period_end), $i->currency, (string) $i->total_amount, $i->current_status->value, $i->superseded_by_id, $i->evidence_ref]);
                    }
                    $w->flush();
                });

            $w->section('invoice_lines', ['line_id', 'invoice_id', 'line_no', 'kind', 'description_code', 'amount', 'currency']);
            CostInvoiceLine::query()->select('cost_invoice_lines.*')
                ->join('cost_invoices', 'cost_invoices.id', '=', 'cost_invoice_lines.cost_invoice_id')
                ->where('cost_invoices.period_start', '>=', $fromValue)->where('cost_invoices.period_start', '<', $toValue)
                ->chunkById(500, function ($lines) use ($w): void {
                    foreach ($lines as $l) {
                        $w->row('invoice_lines', [$l->id, $l->cost_invoice_id, $l->line_no, $l->kind->value, $l->description_code, (string) $l->amount, $l->currency]);
                    }
                    $w->flush();
                }, 'cost_invoice_lines.id', 'id');

            $w->section('evidence_allocations', ['allocation_id', 'invoice_id', 'line_id', 'reconciliation_id', 'amount', 'currency', 'source_amount', 'source_currency', 'fx_rate_id', 'fx_rate_date', 'fx_rate_snapshot', 'fx_direction']);
            CostInvoiceAllocation::query()->select('cost_invoice_allocations.*')
                ->join('cost_reconciliations', 'cost_reconciliations.id', '=', 'cost_invoice_allocations.cost_reconciliation_id')
                ->where('cost_reconciliations.period_start', '>=', $fromValue)->where('cost_reconciliations.period_start', '<', $toValue)
                ->chunkById(500, function ($rows) use ($w): void {
                    foreach ($rows as $a) {
                        $w->row('evidence_allocations', [$a->id, $a->cost_invoice_id, $a->cost_invoice_line_id, $a->cost_reconciliation_id, (string) $a->amount, $a->currency, $a->source_amount === null ? null : (string) $a->source_amount, $a->source_currency, $a->fx_rate_id, $a->fx_rate_date?->format('Y-m-d'), $a->fx_rate_snapshot === null ? null : (string) $a->fx_rate_snapshot, $a->fx_direction?->value]);
                    }
                    $w->flush();
                }, 'cost_invoice_allocations.id', 'id');

            $w->section('adjustments', ['adjustment_id', 'reconciliation_id', 'amount', 'currency', 'reason_code', 'evidence_ref', 'created_at_utc']);
            CostAdjustment::query()->select('cost_adjustments.*')
                ->join('cost_reconciliations', 'cost_reconciliations.id', '=', 'cost_adjustments.cost_reconciliation_id')
                ->where('cost_reconciliations.period_start', '>=', $fromValue)->where('cost_reconciliations.period_start', '<', $toValue)
                ->chunkById(500, function ($rows) use ($w): void {
                    foreach ($rows as $adj) {
                        $w->row('adjustments', [$adj->id, $adj->cost_reconciliation_id, (string) $adj->amount, $adj->currency, $adj->reason_code, $adj->evidence_ref, CsvWriter::utc($adj->created_at)]);
                    }
                    $w->flush();
                }, 'cost_adjustments.id', 'id');
        });
    }
}
