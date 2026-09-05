<?php

declare(strict_types=1);

namespace App\Data\Finance;

/**
 * One group of a financial breakdown (per plan, provider/model, operation/
 * channel, subscriber or date bucket). knownCost is a decimal string from the
 * PRICED rows of the group only; unpricedRows says how much of the group has
 * no known cost.
 */
final readonly class CostBucket
{
    /**
     * @param  array<string, int|string|null>  $dimensions  e.g. ['plan_id' => 3, 'plan_slug' => 'basic']
     */
    public function __construct(
        public array $dimensions,
        public int $rows,
        public int $pricedRows,
        public int $unpricedRows,
        public string $knownCost,
        public string $knownProviderCost,
        public string $knownCommunicationCost,
        public int $inputUnits,
        public int $outputUnits,
    ) {}
}
