<?php

declare(strict_types=1);

namespace App\Services\Billing\Pricing;

use App\Data\Ai\Catalog\ModelSpec;
use App\Services\Settings\SettingsRepository;
use Carbon\CarbonImmutable;

/**
 * Cost guardrail FOUNDATION: estimates what one request to a catalog model
 * would cost now, from its current price and a configurable typical request
 * size (settings ai.guardrails.estimate_*_tokens). Returns null when the cost is
 * unknown (model not in the DB catalog, or no current price) — the router
 * never skips a candidate on an unknown estimate; it only enforces known ones.
 */
class CostEstimator
{
    public function __construct(
        private readonly PriceBook $prices,
        private readonly CostCalculator $calculator,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * Estimated provider cost of one request, in billing.cost_currency, or null.
     */
    public function estimate(ModelSpec $spec, ?int $inputTokens = null, ?int $outputTokens = null): ?float
    {
        if ($spec->modelId === null) {
            return null;
        }

        $price = $this->prices->priceFor($spec->modelId, CarbonImmutable::now());

        if ($price === null || strtoupper($price->currency) !== strtoupper((string) config('billing.cost_currency', 'USD'))) {
            return null;
        }

        $inputTokens ??= (int) $this->settings->get('ai.guardrails.estimate_input_tokens');
        $outputTokens ??= (int) $this->settings->get('ai.guardrails.estimate_output_tokens');

        return (float) $this->calculator->estimateTokens($price, $inputTokens, $outputTokens);
    }
}
