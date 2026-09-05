<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Data\Finance\CostBucket;
use App\Data\Finance\GrossMarginStatus;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streaming CSV export of the CALCULATED finance aggregates (Phase D2), built
 * on the very same FinanceQuery / MrrCalculator / MrrSnapshotHistory calls as
 * the page, so the file can never disagree with the screen.
 *
 * One file, every row starting with a `section` column; each section opens
 * with its own header row and sections are separated by a blank line:
 *   meta · current_run_rate · unassigned · cost_totals · cost_coverage ·
 *   gross_margin · by_plan · by_provider_model · by_operation_channel ·
 *   top_subscribers · cost_trend · mrr_snapshot_history
 *
 * Contract: calculated_not_collected=true, timezone=UTC,
 * historical_revenue_available=false, gross_margin_available=false — and there
 * is NO numeric gross profit anywhere in the file. No PII: subscribers appear
 * as their internal id only.
 */
final class FinanceExporter
{
    public function __construct(
        private readonly FinanceQuery $finance,
        private readonly MrrCalculator $mrr,
        private readonly MrrSnapshotHistory $history,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function stream(CarbonImmutable $from, CarbonImmutable $to, array $filters, string $granularity, int $top): StreamedResponse
    {
        $granularity = $granularity === 'month' ? 'month' : 'day';
        $top = max(1, min(FinanceQuery::TOP_LIMIT_MAX, $top));
        $filename = sprintf('sanad-finance-calculated-%s-%s.csv', $from->format('Ymd'), $to->subDay()->format('Ymd'));

        return response()->streamDownload(function () use ($from, $to, $filters, $granularity, $top): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            $put = static function (array $row) use ($out): void {
                fputcsv($out, $row);
            };
            $blank = static function () use ($out): void {
                fwrite($out, "\n");
                if (function_exists('flush')) {
                    flush();
                }
            };

            fwrite($out, "\xEF\xBB\xBF");

            $current = $this->mrr->current();
            $query = $this->finance->build($from, $to, $filters);
            $totals = $this->finance->totals($query);
            $coverage = $this->finance->coverage($totals);
            $margin = GrossMarginStatus::forWindow($totals, $coverage, $current);

            // ── meta ────────────────────────────────────────────────────────
            $put(['section', 'key', 'value']);
            $meta = [
                'calculated_not_collected' => 'true',
                'timezone' => 'UTC',
                'window_from' => $from->toDateString(),
                'window_to' => $to->subDay()->toDateString(),
                'cost_currency' => $totals->currency,
                'cost_coverage' => sprintf('provider=%s;communication=%s;external=%s', $coverage->provider->value, $coverage->communication->value, $coverage->external->value),
                'cost_coverage_warnings' => implode(' | ', $coverage->warnings()),
                'known_cost_is_full_service_cost' => $coverage->knownCostIsFullServiceCost() ? 'true' : 'false',
                'unpriced_rows' => (string) $totals->unpricedRows,
                'mrr_as_of' => $current->asOf->toIso8601String(),
                'mrr_calculation_version' => (string) $current->calculationVersion,
                'historical_revenue_available' => 'false',
                'gross_margin_available' => 'false',
                'gross_margin_status' => $margin->label(),
                'gross_margin_reasons' => implode(';', $margin->reasons),
                'generated_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            ];
            foreach ($filters as $key => $value) {
                if ($value !== null && $value !== '') {
                    $meta['filter_'.$key] = (string) $value;
                }
            }
            $meta['trend_granularity'] = $granularity;
            $meta['top_subscribers_limit'] = (string) $top;
            foreach ($meta as $key => $value) {
                $put(['meta', $key, $value]);
            }
            $blank();

            // ── current run-rate (as of now; not the window) ────────────────
            $put(['section', 'currency', 'current_calculated_mrr', 'current_calculated_arr', 'current_calculated_arpu', 'active', 'trialing', 'past_due_status_count']);
            foreach ($current->byCurrency() as $currency => $kpi) {
                $put(['current_run_rate', $currency, $kpi['mrr'], $kpi['arr'], $kpi['arpu'] ?? 'N/A', $kpi['active'], $kpi['trialing'], $kpi['past_due']]);
            }
            $blank();

            $u = $current->unassigned();
            $put(['section', 'active', 'trialing', 'past_due_status_count', 'note']);
            $put(['unassigned', $u['active'], $u['trialing'], $u['past_due'], 'subscriptions without a plan: not a currency, never revenue']);
            $blank();

            // ── cost totals / coverage / margin ─────────────────────────────
            $put(['section', 'rows', 'priced_rows', 'unpriced_rows', 'known_provider_cost', 'known_communication_cost', 'known_external_cost', 'known_total_cost', 'currency', 'input_units', 'output_units', 'unpriced_input_units', 'unpriced_output_units', 'system_rows']);
            $put(['cost_totals', $totals->rows, $totals->pricedRows, $totals->unpricedRows, $totals->knownProviderCost, $this->componentOrStatus($totals->knownCommunicationCost, $coverage->communication->value), $this->componentOrStatus($totals->knownExternalCost, $coverage->external->value), $totals->knownTotalCost, $totals->currency, $totals->inputUnits, $totals->outputUnits, $totals->unpricedInputUnits, $totals->unpricedOutputUnits, $totals->systemRows]);
            $blank();

            $put(['section', 'component', 'status', 'detail']);
            $put(['cost_coverage', 'provider', $coverage->provider->value, $coverage->providerUnpricedRows.' unpriced rows']);
            $put(['cost_coverage', 'communication', $coverage->communication->value, $coverage->communicationUncoveredRows.' rows with WhatsApp or unknown channel']);
            $put(['cost_coverage', 'external', $coverage->external->value, 'no producer']);
            $blank();

            $put(['section', 'status', 'reason']);
            foreach ($margin->reasons as $reason) {
                $put(['gross_margin', $margin->label(), $reason]);
            }
            $blank();

            // ── breakdowns ──────────────────────────────────────────────────
            $this->buckets($put, 'by_plan', ['attribution', 'plan_id', 'plan_slug'], $this->finance->byPlan($query), $totals->currency);
            $blank();
            $this->buckets($put, 'by_provider_model', ['provider', 'model'], $this->finance->byProviderModel($query), $totals->currency);
            $blank();
            $this->buckets($put, 'by_operation_channel', ['operation', 'channel'], $this->finance->byOperationChannel($query), $totals->currency);
            $blank();
            $this->buckets($put, 'top_subscribers', ['subscriber_id'], $this->finance->topSubscribers($query, $top), $totals->currency);
            $blank();
            $this->buckets($put, 'cost_trend_utc_'.$granularity, ['bucket'], $this->finance->trend($query, $granularity), $totals->currency);
            $blank();

            // ── MRR snapshot history (run-rate, not revenue) ────────────────
            $series = $this->history->series($from, $to->subDay());
            $put(['section', 'date_utc', 'status', 'currency', 'mrr_run_rate', 'active', 'trialing', 'past_due_status_count', 'captured_at']);
            foreach ($series->days as $day) {
                if (! $day->isCaptured()) {
                    $put(['mrr_snapshot_history', $day->date, strtoupper(str_replace('_', ' ', $day->status)), '', '', '', '', '', '']);

                    continue;
                }

                if ($day->byCurrency === []) {
                    $put(['mrr_snapshot_history', $day->date, 'CAPTURED', '', '', '', '', '', $day->capturedAt]);

                    continue;
                }

                foreach ($day->byCurrency as $currency => $entry) {
                    $put(['mrr_snapshot_history', $day->date, 'CAPTURED', $currency, $entry['mrr'], $entry['active'], $entry['trialing'], $entry['past_due'], $day->capturedAt]);
                }
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @param  callable(array<int, mixed>): void  $put
     * @param  list<string>  $dimensions
     * @param  list<CostBucket>  $buckets
     */
    private function buckets(callable $put, string $section, array $dimensions, array $buckets, string $currency): void
    {
        $put(['section', ...$dimensions, 'rows', 'priced_rows', 'unpriced_rows', 'known_cost', 'known_provider_cost', 'known_communication_cost', 'currency', 'input_units', 'output_units']);

        foreach ($buckets as $bucket) {
            $values = array_map(static fn (string $d) => $bucket->dimensions[$d] ?? '', $dimensions);
            $put([$section, ...$values, $bucket->rows, $bucket->pricedRows, $bucket->unpricedRows, $bucket->knownCost, $bucket->knownProviderCost, $bucket->knownCommunicationCost, $currency, $bucket->inputUnits, $bucket->outputUnits]);
        }
    }

    /** A component without complete coverage is exported as its status, never as a "0" amount. */
    private function componentOrStatus(string $amount, string $coverageStatus): string
    {
        return $coverageStatus === 'complete' ? $amount : strtoupper(str_replace('_', ' ', $coverageStatus));
    }
}
