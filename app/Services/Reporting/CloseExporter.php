<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Models\FinancePeriodClose;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Close CSV (Phase E5.1) — FROZEN data only: the close row, its frozen
 * figures and conditions, and its finance_period_close_inputs rows, read
 * through FrozenCloseReader. No preflight, no live payment / reconciliation /
 * FX state, no drift. Sections: meta · figures · conditions ·
 * expected_providers · inputs. NULL figure = empty cell + NOT AVAILABLE.
 */
final class CloseExporter
{
    public function __construct(private readonly FrozenCloseReader $reader) {}

    public function stream(FinancePeriodClose $close): StreamedResponse
    {
        $filename = sprintf('sanad-finance-close-%s-%s-rev%d-%d.csv', $close->month(), $close->reporting_currency, $close->revision, $close->id);

        return CsvWriter::download($filename, function (CsvWriter $w) use ($close): void {
            $detail = $this->reader->detail($close);
            $snapshot = (array) $close->inputs_snapshot;

            $w->meta([
                'basis' => $detail->basisLabel(),
                'vocabulary' => 'frozen period close — Reconciled Cash Contribution is a cash-basis internal metric (not gross profit, margin, revenue or accounting profit)',
                'close_id' => $close->id,
                'status' => $close->status->value,
                'month' => $close->month(),
                'period_start_utc' => CsvWriter::utc($close->period_start),
                'period_end_utc' => CsvWriter::utc($close->period_end),
                'reporting_currency' => $close->reporting_currency,
                'revision' => $close->revision,
                'previous_close_id' => $close->previous_close_id,
                'reopened_close_id' => $close->reopened_close_id,
                'is_current_close' => $detail->isCurrent ? 'true' : 'false',
                'input_hash' => $close->input_hash,
                'snapshot_version' => $snapshot['version'] ?? null,
                'closed_at_utc' => CsvWriter::utc($close->closed_at),
                'actor_ref' => $close->actor_ref,
                'reason_code' => $close->reason_code,
                'evidence_ref' => $close->evidence_ref,
                'unknown_semantics' => 'empty numeric cell + NOT AVAILABLE; never 0',
            ]);

            $w->section('figures', ['figure', 'amount', 'reporting_currency', 'status']);
            foreach (FrozenCloseReader::FIGURES as $key) {
                $value = $close->getAttribute($key);
                $w->row('figures', [$key, $value === null ? null : (string) $value, $close->reporting_currency, $value === null ? 'NOT AVAILABLE' : 'frozen']);
            }

            $w->section('conditions', ['code', 'blocking', 'detail']);
            foreach ($detail->conditions() as $condition) {
                $w->row('conditions', [$condition['code'], ($condition['blocking'] ?? false) ? 'true' : 'false', $condition['detail'] ?? '']);
            }

            $w->section('expected_providers', ['provider_key']);
            foreach ((array) ($snapshot['expected_providers'] ?? []) as $provider) {
                $w->row('expected_providers', [(string) $provider]);
            }

            $w->section('inputs', ['input_type', 'input_id', 'amount', 'currency', 'scale', 'status', 'reporting_amount', 'reporting_currency', 'fx_conversion_id', 'fx_rate_id', 'fx_rate_date', 'fx_rate_snapshot', 'fx_direction', 'flags']);
            foreach ($detail->inputs as $type => $rows) {
                foreach ($rows as $row) {
                    $w->row('inputs', [$type, $row->input_id, $row->status === 'FEES UNKNOWN' ? null : (string) $row->amount, $row->currency, $row->scale, $row->status, $row->reporting_amount === null ? null : (string) $row->reporting_amount, $row->reporting_currency, $row->fx_conversion_id, $row->fx_rate_id, $row->fx_rate_id === null ? null : ($detail->rateDates[$row->fx_rate_id] ?? null), $row->fx_rate_snapshot === null ? null : (string) $row->fx_rate_snapshot, $row->fx_direction, implode(' | ', (array) $row->flags)]);
                }
            }
        });
    }
}
