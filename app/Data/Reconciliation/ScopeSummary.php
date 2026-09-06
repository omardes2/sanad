<?php

declare(strict_types=1);

namespace App\Data\Reconciliation;

/**
 * One reconciliation scope as the query reports it. Amounts are decimal
 * strings (scale 6). Variances are NULL (UNKNOWN) unless the frozen
 * calculated side was COMPLETE; adjustments never rewrite the base figures.
 */
final readonly class ScopeSummary
{
    /**
     * @param  list<string>  $flags  e.g. LEDGER MOVED SINCE RECONCILIATION, EVIDENCE SUPERSEDED, EVIDENCE VOIDED
     */
    public function __construct(
        public int $scopeId,
        public string $component,
        public string $counterpartyKey,
        public string $month,
        public string $currency,
        public ?int $reconciliationId,
        public ?string $source,
        public string $status,
        public ?string $baseReconciledAmount,
        public string $adjustments,
        public ?string $adjustedReconciledCost,
        public ?string $calculatedKnownAmount,
        public ?int $calculatedPricedRows,
        public ?int $unpricedRows,
        public ?int $currencyMismatchRows,
        public ?string $coverage,
        public ?string $varianceVsKnownCalculated,
        public ?string $adjustedVarianceVsKnownCalculated,
        public string $varianceStatus,
        public bool $ledgerMoved,
        public array $flags,
    ) {}
}
