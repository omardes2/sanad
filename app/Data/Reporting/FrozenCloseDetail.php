<?php

declare(strict_types=1);

namespace App\Data\Reporting;

use App\Models\FinancePeriodClose;
use App\Models\FinancePeriodCloseInput;
use App\Models\FinancePeriodCloseScope;

/**
 * A historical close exactly as it was frozen: the close row, its scope, and
 * its input rows grouped by type. Nothing here comes from a live evaluation.
 * `rateDates` maps fx_rate_id → rate_date, an immutable attribute of the
 * append-only fx_rates row the input named at close time.
 */
final readonly class FrozenCloseDetail
{
    /**
     * @param  array<string, list<FinancePeriodCloseInput>>  $inputs  keyed by input type in display order
     * @param  array<int, string>  $rateDates
     * @param  array<int, list<string>>  $evidence  reconciliation id → "invoice:#x line:#y" from the append-only cost_invoice_allocations rows (immutable evidence references, never amounts)
     */
    public function __construct(
        public FinancePeriodClose $close,
        public FinancePeriodCloseScope $scope,
        public array $inputs,
        public array $rateDates,
        public bool $isCurrent,
        public array $evidence = [],
    ) {}

    public function basisLabel(): string
    {
        return $this->close->status->value === 'closed' ? MonthFigures::FROZEN.' '.$this->close->revision : 'REOPEN RECORD (revision '.$this->close->revision.')';
    }

    /** @return list<array{code: string, blocking: bool, detail: string}> */
    public function conditions(): array
    {
        return array_values((array) $this->close->conditions);
    }

    public function inputCount(): int
    {
        return array_sum(array_map('count', $this->inputs));
    }
}
