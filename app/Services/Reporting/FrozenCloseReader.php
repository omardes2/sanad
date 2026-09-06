<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Data\Reporting\FrozenCloseDetail;
use App\Data\Reporting\MonthFigures;
use App\Enums\CloseInputType;
use App\Enums\PeriodCloseStatus;
use App\Models\FinancePeriodClose;
use App\Models\FinancePeriodCloseInput;
use App\Models\FinancePeriodCloseScope;
use App\Models\FxRate;
use App\Support\Reconciliation\ReconciliationRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Read model over the FROZEN close tables (Phase E5.1) — reads only:
 * finance_period_close_scopes, finance_period_closes,
 * finance_period_close_inputs. It never calls ClosePreflight, never looks at
 * live payments / reconciliations / FX conversions, and never computes drift:
 * a historical close is rendered from its own row and its own input rows.
 * Query count is fixed (independent of the number of closes or input rows).
 */
final class FrozenCloseReader
{
    public const MAX_MONTHS = 13;

    /**
     * The calendar months UTC overlapping [from, to) — at most 13.
     *
     * @return list<string> YYYY-MM
     */
    public static function monthsCovering(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $cursor = $from->utc()->startOfMonth();
        $last = $to->utc()->subSecond()->startOfMonth();
        $months = [];

        while ($cursor <= $last && count($months) < self::MAX_MONTHS) {
            $months[] = $cursor->format('Y-m');
            $cursor = $cursor->addMonth();
        }

        return $months;
    }

    /**
     * The scope of every listed month in ONE reporting currency (one query), keyed by month.
     *
     * @param  list<string>  $months
     * @return Collection<string, FinancePeriodCloseScope>
     */
    public function scopes(array $months, string $reportingCurrency): Collection
    {
        if ($months === []) {
            return collect();
        }

        $starts = array_map(static fn (string $m): string => ReconciliationRules::month($m)[0]->format('Y-m-d H:i:s'), $months);

        return FinancePeriodCloseScope::query()->where('reporting_currency', $reportingCurrency)->whereIn('period_start', $starts)->orderBy('period_start')->get()
            ->keyBy(fn (FinancePeriodCloseScope $s): string => $s->month());
    }

    /**
     * The CURRENT close of every closed scope in the collection (one query), keyed by month.
     * A scope that is open or reopened has no frozen figures — it is LIVE / CURRENT.
     *
     * @param  Collection<string, FinancePeriodCloseScope>  $scopes
     * @return Collection<string, FinancePeriodClose>
     */
    public function currentCloses(Collection $scopes): Collection
    {
        $ids = $scopes->filter(fn (FinancePeriodCloseScope $s): bool => $s->isClosed() && $s->current_close_id !== null)->pluck('current_close_id')->all();

        if ($ids === []) {
            return collect();
        }

        return FinancePeriodClose::query()->whereIn('id', $ids)->where('status', PeriodCloseStatus::Closed->value)->get()->keyBy(fn (FinancePeriodClose $c): string => $c->month());
    }

    /** Frozen figures for a month from its CURRENT close row only. */
    public static function frozen(FinancePeriodClose $close): MonthFigures
    {
        $figures = [];
        foreach (self::FIGURES as $key) {
            $value = $close->getAttribute($key);
            $figures[$key] = $value === null ? null : (string) $value;
        }

        $conditions = array_values((array) $close->conditions);

        return new MonthFigures(
            $close->month(), $close->reporting_currency, MonthFigures::FROZEN, 'closed', $close->id, $close->revision, $close->input_hash,
            $figures,
            [], // a close only ever exists without blocking conditions
            array_values(array_map(static fn (array $c): string => $c['code'].($c['detail'] === '' ? '' : ' ('.$c['detail'].')'), array_filter($conditions, static fn (array $c): bool => ! ($c['blocking'] ?? false)))),
        );
    }

    public const FIGURES = ['gross_cash_collected', 'refunds', 'net_cash', 'gateway_fees', 'net_cash_after_gateway_fees', 'reconciled_service_cost', 'reconciled_cash_contribution'];

    /**
     * Every close / reopen record of a scope, newest first — frozen columns only, no drift.
     *
     * @return Collection<int, FinancePeriodClose>
     */
    public function history(FinancePeriodCloseScope $scope): Collection
    {
        return FinancePeriodClose::query()->where('scope_id', $scope->id)->orderByDesc('id')->get();
    }

    /**
     * A close exactly as frozen: row + scope + input rows grouped by type (+ the
     * immutable rate_date of every fx_rates row named by an input). Three queries.
     */
    public function detail(FinancePeriodClose $close): FrozenCloseDetail
    {
        $scope = FinancePeriodCloseScope::query()->whereKey($close->scope_id)->firstOrFail();
        $rows = FinancePeriodCloseInput::query()->where('close_id', $close->id)->orderBy('input_type')->orderBy('input_id')->get();

        $grouped = [];
        foreach (CloseInputType::cases() as $type) {
            $grouped[$type->value] = [];
        }
        foreach ($rows as $row) {
            $grouped[$row->input_type->value][] = $row;
        }

        $rateIds = $rows->pluck('fx_rate_id')->filter()->unique()->values()->all();
        $rateDates = $rateIds === [] ? [] : FxRate::query()->whereIn('id', $rateIds)->get(['id', 'rate_date'])->mapWithKeys(fn (FxRate $r) => [$r->id => $r->rateDate()])->all();

        return new FrozenCloseDetail($close, $scope, $grouped, $rateDates, $scope->current_close_id === $close->id);
    }
}
