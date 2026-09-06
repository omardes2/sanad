<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Data\Reporting\MonthFigures;
use App\Models\FinancePeriodCloseScope;
use App\Services\Close\ClosePreflight;
use App\Services\Fx\ReportingCurrencyService;
use Carbon\CarbonImmutable;

/**
 * The Reconciled band of the finance overview (Phase E5.1): one row per
 * calendar month UTC overlapping the window, in the CURRENT reporting
 * currency only, each with exactly one basis —
 *  - FROZEN CLOSE REVISION n: the scope's current close (finance_period_closes
 *    row; nothing live touches it);
 *  - LIVE / CURRENT: no current close ⇒ the live preflight, blockers included.
 * Rows are a series, never a total: months, reporting currencies and
 * revisions are never added together. Closes in another reporting currency
 * are not part of this band (they stay in the close history).
 */
final class ReconciledMonthSeries
{
    public function __construct(private readonly FrozenCloseReader $reader, private readonly ClosePreflight $preflight, private readonly ReportingCurrencyService $reporting) {}

    /**
     * @return list<MonthFigures>
     */
    public function forWindow(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $target = $this->reporting->current();
        $months = FrozenCloseReader::monthsCovering($from, $to);
        $scopes = $this->reader->scopes($months, $target);
        $closes = $this->reader->currentCloses($scopes);
        $out = [];

        foreach ($months as $month) {
            if ($closes->has($month)) {
                $out[] = FrozenCloseReader::frozen($closes->get($month));

                continue;
            }

            /** @var FinancePeriodCloseScope|null $scope */
            $scope = $scopes->get($month);
            $evaluation = $this->preflight->evaluate($month, $target);

            $out[] = new MonthFigures(
                $month, $target, MonthFigures::LIVE, $scope === null ? 'never closed' : $scope->state, null, null, $evaluation->inputHash,
                $evaluation->metrics, $evaluation->blocking(),
                array_values(array_map(static fn (array $c): string => $c['code'].' ('.$c['detail'].')', array_filter($evaluation->conditions, static fn (array $c): bool => ! $c['blocking']))),
            );
        }

        return $out;
    }
}
