<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What the CALCULATED side of a scope means at snapshot time. Only `complete`
 * allows a numeric variance; anything else makes the variance UNKNOWN — a
 * calculated zero without a producer is not a fact to compare against.
 */
enum CostCoverageStatus: string
{
    /** Every ledger row in scope is priced in the scope currency. */
    case Complete = 'complete';

    /** Some rows are unpriced or in another currency: the known amount is a floor, not the cost. */
    case Partial = 'partial';

    /** Nothing records this component at all: the calculated figure is absent, not zero. */
    case NoProducer = 'no_producer';

    public function allowsVariance(): bool
    {
        return $this === self::Complete;
    }

    public function label(): string
    {
        return match ($this) {
            self::Complete => 'KNOWN (complete)',
            self::Partial => 'PARTIAL (unpriced / mismatch rows)',
            self::NoProducer => 'NO PRODUCER',
        };
    }
}
