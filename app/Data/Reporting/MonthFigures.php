<?php

declare(strict_types=1);

namespace App\Data\Reporting;

use App\Data\Reconciliation\ScopeSummary;

/**
 * One calendar month UTC in the Reconciled band of the finance overview, in
 * ONE reporting currency (the current one). Exactly one basis:
 *  - FROZEN CLOSE REVISION n — the scope's CURRENT close (state closed): the
 *    seven figures, conditions and hash come from finance_period_closes only;
 *  - LIVE / CURRENT — no current close (never closed, or reopened): the
 *    figures come from the live preflight and may still be blocked.
 * Months are never summed with each other; NULL figure = NOT AVAILABLE.
 */
final readonly class MonthFigures
{
    public const FROZEN = 'FROZEN CLOSE REVISION';

    public const LIVE = 'LIVE / CURRENT';

    /**
     * @param  array<string, ?string>  $figures  the seven cash-basis figures
     * @param  list<string>  $blocking  blocking condition labels (live only; a frozen close has none)
     * @param  list<string>  $informational
     * @param  list<ScopeSummary>  $scopes  LIVE months only: the current reconciliation per scope (Calculated vs Reconciled coverage / variance status, flags) from ReconciledCostQuery; a frozen month keeps its frozen conditions instead
     */
    public function __construct(
        public string $month,
        public string $reportingCurrency,
        public string $basis,
        public string $state,
        public ?int $closeId,
        public ?int $revision,
        public ?string $inputHash,
        public array $figures,
        public array $blocking,
        public array $informational,
        public array $scopes = [],
    ) {}

    /** The E2 flags of the live scopes (LEDGER MOVED SINCE RECONCILIATION, EVIDENCE VOIDED / SUPERSEDED …) as warnings. */
    public function warnings(): array
    {
        $out = [];
        foreach ($this->scopes as $scope) {
            foreach ($scope->flags as $flag) {
                $out[] = $flag.' · '.$scope->component.':'.$scope->counterpartyKey.' (reconciliation:'.$scope->reconciliationId.')';
            }
        }

        return $out;
    }

    public function isFrozen(): bool
    {
        return $this->closeId !== null;
    }

    public function basisLabel(): string
    {
        return $this->isFrozen() ? self::FROZEN.' '.$this->revision : self::LIVE;
    }

    public function figure(string $key): string
    {
        return $this->figures[$key] ?? 'NOT AVAILABLE';
    }
}
