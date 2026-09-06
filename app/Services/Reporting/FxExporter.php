<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Models\FxConversion;
use App\Models\FxConversionScope;
use App\Models\FxPair;
use App\Models\FxRate;
use App\Models\FxRateScope;
use App\Services\Fx\ReportingCurrencyService;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * FX CSV (Phase E5.1) — read-only: every canonical pair, every quote
 * revision whose rate_date is in the window (with whether it is the scope's
 * CURRENT revision), and every frozen reporting conversion whose subject
 * policy date is in the window (with whether it is the subject's CURRENT
 * conversion). Sections: meta · pairs · rates · conversions. Nothing is
 * looked up, interpolated or recomputed.
 */
final class FxExporter
{
    public function __construct(private readonly ReportingCurrencyService $reporting) {}

    public function stream(CarbonImmutable $from, CarbonImmutable $to): StreamedResponse
    {
        $filename = sprintf('sanad-finance-fx-%s-%s.csv', $from->format('Ymd'), $to->subDay()->format('Ymd'));

        return CsvWriter::download($filename, function (CsvWriter $w) use ($from, $to): void {
            $w->meta([
                'basis' => 'LIVE / CURRENT',
                'vocabulary' => 'manual point-in-time quotes and frozen reporting conversions — no lookup, no nearest/latest rate, no recomputation',
                'window_from' => $from->toDateString(),
                'window_to' => $to->subDay()->toDateString(),
                'reporting_currency' => $this->reporting->current(),
            ]);

            $w->section('pairs', ['pair_id', 'pair_key', 'base_currency', 'quote_currency']);
            foreach (FxPair::query()->orderBy('id')->get() as $pair) {
                $w->row('pairs', [$pair->id, $pair->pair_key, $pair->base_currency, $pair->quote_currency]);
            }

            $w->section('rates', ['rate_id', 'pair_id', 'base_currency', 'quote_currency', 'rate_date', 'rate', 'source', 'evidence_ref', 'reason_code', 'supersedes_id', 'is_current_revision']);
            FxRate::query()->where('rate_date', '>=', $from->toDateString())->where('rate_date', '<', $to->toDateString())
                ->chunkById(500, function ($rates) use ($w): void {
                    $current = FxRateScope::query()->whereIn('id', $rates->pluck('scope_id')->unique()->all())->pluck('current_rate_id', 'id');
                    foreach ($rates as $r) {
                        $w->row('rates', [$r->id, $r->fx_pair_id, $r->base_currency, $r->quote_currency, $r->rateDate(), (string) $r->rate, $r->source, $r->evidence_ref, $r->reason_code, $r->supersedes_id, ($current->get($r->scope_id) === $r->id) ? 'true' : 'false']);
                    }
                    $w->flush();
                });

            $w->section('conversions', ['conversion_id', 'subject_type', 'subject_id', 'purpose', 'policy_date', 'source_amount', 'source_currency', 'fx_rate_id', 'fx_rate_date', 'rate_snapshot', 'direction', 'target_amount', 'target_currency', 'supersedes_id', 'reason_code', 'is_current_conversion']);
            FxConversion::query()->where('subject_date', '>=', $from->format('Y-m-d H:i:s'))->where('subject_date', '<', $to->format('Y-m-d H:i:s'))
                ->chunkById(500, function ($conversions) use ($w): void {
                    $current = FxConversionScope::query()->whereIn('id', $conversions->pluck('scope_id')->unique()->all())->pluck('current_conversion_id', 'id');
                    foreach ($conversions as $c) {
                        $w->row('conversions', [$c->id, $c->subject_type, $c->subject_id, $c->purpose->value, $c->subject_date?->format('Y-m-d'), (string) $c->source_amount, $c->source_currency, $c->fx_rate_id, $c->fx_rate_date?->format('Y-m-d'), (string) $c->rate_snapshot, $c->direction->value, $c->targetAmountAtScale(), $c->target_currency, $c->supersedes_id, $c->reason_code, ($current->get($c->scope_id) === $c->id) ? 'true' : 'false']);
                    }
                    $w->flush();
                });
        });
    }
}
