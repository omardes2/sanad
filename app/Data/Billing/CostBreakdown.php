<?php

declare(strict_types=1);

namespace App\Data\Billing;

use App\Enums\CostSource;

/**
 * The costed result of one usage record, as CostCalculator produces it and
 * UsageRecorder stores it. Amounts are decimal strings with 6 fractional
 * digits (the ledger's scale). When `source` is not a known cost the amounts
 * are 0 and MUST be read as "unpriced / unknown", never as "free".
 *
 * @param  array<string, mixed>|null  $snapshot  the rates used (null when no price applied)
 */
final readonly class CostBreakdown
{
    /**
     * @param  array<string, mixed>|null  $snapshot
     */
    public function __construct(
        public string $providerCost,
        public string $communicationCost,
        public string $externalCost,
        public string $totalCost,
        public string $currency,
        public CostSource $source,
        public ?int $aiModelId = null,
        public ?int $modelPriceId = null,
        public ?array $snapshot = null,
    ) {}

    public function isKnown(): bool
    {
        return $this->source->isKnown();
    }
}
