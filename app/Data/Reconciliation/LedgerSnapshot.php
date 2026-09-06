<?php

declare(strict_types=1);

namespace App\Data\Reconciliation;

use App\Enums\CostComponent;
use App\Enums\CostCoverageStatus;
use App\Support\Reconciliation\ReconciliationRules;
use Carbon\CarbonImmutable;

/**
 * What the calculated ledger showed for one scope at one instant — enough to
 * prove later what finance saw: the KNOWN amount (priced rows in the scope
 * currency only), the priced / unpriced / currency-mismatch row counts, the
 * highest event id in scope, the coverage status and a canonical hash.
 * A zero known amount without a producer is NOT a fact; coverage says so.
 */
final readonly class LedgerSnapshot
{
    public function __construct(
        public CostComponent $component,
        public string $counterpartyKey,
        public CarbonImmutable $periodStart,
        public CarbonImmutable $periodEnd,
        public string $currency,
        public int $knownScaled,
        public int $pricedRows,
        public int $unpricedRows,
        public int $currencyMismatchRows,
        public ?int $maxEventId,
        public CostCoverageStatus $coverage,
        public CarbonImmutable $capturedAt,
    ) {}

    public function knownAmount(): string
    {
        return ReconciliationRules::format($this->knownScaled);
    }

    /** Canonical, engine-independent fingerprint of the ledger state in scope. */
    public function hash(): string
    {
        return hash('sha256', implode('|', [
            'v1',
            $this->component->value,
            $this->counterpartyKey,
            $this->periodStart->utc()->format('Y-m-d H:i:s'),
            $this->periodEnd->utc()->format('Y-m-d H:i:s'),
            $this->currency,
            (string) $this->knownScaled,
            (string) $this->pricedRows,
            (string) $this->unpricedRows,
            (string) $this->currencyMismatchRows,
            (string) ($this->maxEventId ?? 0),
            $this->coverage->value,
        ]));
    }
}
