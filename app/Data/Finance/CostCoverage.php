<?php

declare(strict_types=1);

namespace App\Data\Finance;

use App\Enums\CoverageStatus;

/**
 * Cost coverage of a window, per component. `knownCostIsFullServiceCost()` is
 * true ONLY when every component is complete; otherwise the known cost is a
 * partial figure and any margin derived from it is UNKNOWN.
 */
final readonly class CostCoverage
{
    public function __construct(
        public CoverageStatus $provider,
        public int $providerUnpricedRows,
        public CoverageStatus $communication,
        public int $communicationUncoveredRows,
        public CoverageStatus $external,
    ) {}

    public function knownCostIsFullServiceCost(): bool
    {
        return $this->provider->isComplete()
            && $this->communication->isComplete()
            && $this->external->isComplete();
    }

    /**
     * Machine-readable warnings for the dashboard/CSV — wording fixed by the
     * Phase D contract so it can never read as "free".
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        $warnings = [];

        if ($this->provider === CoverageStatus::Incomplete) {
            $warnings[] = "PROVIDER COST COVERAGE INCOMPLETE ({$this->providerUnpricedRows} unpriced rows)";
        }

        if ($this->communication === CoverageStatus::Incomplete) {
            $warnings[] = "COMMUNICATION COST COVERAGE INCOMPLETE ({$this->communicationUncoveredRows} rows with WhatsApp or unknown channel, no communication-cost producer)";
        } elseif ($this->communication === CoverageStatus::NoProducer) {
            $warnings[] = 'COMMUNICATION COST: NO PRODUCER';
        }

        if ($this->external === CoverageStatus::NoProducer) {
            $warnings[] = 'EXTERNAL COST: NO PRODUCER';
        }

        return $warnings;
    }
}
