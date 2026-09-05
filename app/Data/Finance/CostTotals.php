<?php

declare(strict_types=1);

namespace App\Data\Finance;

/**
 * Totals of one ledger window. Money fields are decimal STRINGS at the ledger
 * scale (6) computed from PRICED rows only; unpriced rows are COUNTED (rows and
 * units) and never contribute an amount — their zero is an unknown cost.
 */
final readonly class CostTotals
{
    /**
     * @param  array<string, int>  $unpricedByReason  cost_source reason (or "legacy" for NULL) => rows
     */
    public function __construct(
        public string $currency,
        public int $rows,
        public int $pricedRows,
        public int $unpricedRows,
        public array $unpricedByReason,
        public string $knownProviderCost,
        public string $knownCommunicationCost,
        public string $knownExternalCost,
        public string $knownTotalCost,
        public int $inputUnits,
        public int $outputUnits,
        public int $cachedUnits,
        public int $unpricedInputUnits,
        public int $unpricedOutputUnits,
        public int $systemRows,
        public int $whatsappChannelRows,
        public int $unknownChannelRows,
    ) {}

    public function hasUnpriced(): bool
    {
        return $this->unpricedRows > 0;
    }
}
