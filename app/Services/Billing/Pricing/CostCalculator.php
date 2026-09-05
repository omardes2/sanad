<?php

declare(strict_types=1);

namespace App\Services\Billing\Pricing;

use App\Data\Billing\CostBreakdown;
use App\Data\Billing\UsageRecord;
use App\Enums\CostSource;
use App\Enums\UsageDimension;
use App\Models\ModelPrice;
use App\Services\Ai\Catalog\ModelResolver;
use App\Services\Billing\UsageCostCalculator;
use App\Support\Billing\DecimalMath;
use Carbon\CarbonImmutable;

/**
 * Costs one usage record at the price in force WHEN IT OCCURRED.
 *
 * AI dimensions: resolve the catalog model (alias-aware), look up the price
 * effective at occurred_at, and cost the tokens:
 *
 *   (input − cached) × input_rate + cached × cached_rate + output × output_rate
 *   ────────────────────────────────────────────────────────────────────────── + per_request
 *                                 1,000,000
 *
 * Cached tokens are part of prompt_tokens (OpenAI semantics) so they are
 * subtracted from the input; a price without a cached rate bills them as
 * input. Arithmetic is exact integer fixed-point (no floats, no bcmath) and
 * rounded HALF UP to the ledger's 6 decimals once, at the end.
 *
 * When no DB price applies, the legacy configurable per-dimension rate (B1)
 * is used if it is non-zero (`config_rate`). Otherwise the row is UNPRICED:
 * amounts are 0 and `source` says why (`none` / `currency_mismatch`) — that
 * zero is an unknown cost, never a free operation.
 *
 * WhatsApp dimensions keep the B1 behaviour unchanged (communication cost from
 * the configurable rates).
 */
class CostCalculator
{
    private const RATE_SCALE = 8;

    /** Token rates are per million; scaled rate × tokens is at scale 8 + 6. */
    private const PRODUCT_SCALE = 14;

    public const LEDGER_SCALE = 6;

    public function __construct(
        private readonly PriceBook $prices,
        private readonly ModelResolver $models,
        private readonly UsageCostCalculator $legacy,
    ) {}

    public function calculate(UsageRecord $record, CarbonImmutable $occurredAt): CostBreakdown
    {
        $currency = (string) config('billing.cost_currency', 'USD');
        $zero = DecimalMath::format(0, self::LEDGER_SCALE);

        if (in_array($record->dimension, [UsageDimension::WhatsAppInbound, UsageDimension::WhatsAppOutbound], true)) {
            $communication = $this->legacyAmount($record);

            return new CostBreakdown(
                providerCost: $zero,
                communicationCost: $communication,
                externalCost: $zero,
                totalCost: $communication,
                currency: $currency,
                source: CostSource::ConfigRate,
            );
        }

        $model = $this->models->resolve($record->provider, $record->model, $record->routedModel);
        $price = $model === null ? null : $this->prices->priceFor($model->id, $occurredAt);

        if ($price !== null && strtoupper($price->currency) === strtoupper($currency)) {
            $provider = $this->tokenCost($record, $price);

            return new CostBreakdown(
                providerCost: $provider,
                communicationCost: $zero,
                externalCost: $zero,
                totalCost: $provider,
                currency: $currency,
                source: CostSource::ModelPrice,
                aiModelId: $model?->id,
                modelPriceId: $price->id,
                snapshot: $price->snapshot(),
            );
        }

        // Legacy configurable rate (B1) — only when it is actually set.
        $legacy = $this->legacyAmount($record);

        if ($legacy !== $zero) {
            return new CostBreakdown(
                providerCost: $legacy,
                communicationCost: $zero,
                externalCost: $zero,
                totalCost: $legacy,
                currency: $currency,
                source: CostSource::ConfigRate,
                aiModelId: $model?->id,
            );
        }

        // UNPRICED: unknown cost, recorded as such.
        return new CostBreakdown(
            providerCost: $zero,
            communicationCost: $zero,
            externalCost: $zero,
            totalCost: $zero,
            currency: $currency,
            source: $price !== null ? CostSource::CurrencyMismatch : CostSource::None,
            aiModelId: $model?->id,
            modelPriceId: $price?->id,
            snapshot: $price?->snapshot(),
        );
    }

    /**
     * Estimated provider cost for a hypothetical token usage (guardrails).
     */
    public function estimateTokens(ModelPrice $price, int $inputTokens, int $outputTokens, int $cachedTokens = 0): string
    {
        return $this->tokens($price, $inputTokens, $outputTokens, $cachedTokens);
    }

    private function tokenCost(UsageRecord $record, ModelPrice $price): string
    {
        if ($price->unit !== 'token') {
            // Non-token units are not costed in B2: flat per_request only.
            $perRequest = DecimalMath::toScaled((string) $price->per_request, self::RATE_SCALE);

            return DecimalMath::format(DecimalMath::rescale($perRequest, self::RATE_SCALE, self::LEDGER_SCALE), self::LEDGER_SCALE);
        }

        return $this->tokens($price, $record->inputUnits, $record->outputUnits, $record->cachedUnits);
    }

    private function tokens(ModelPrice $price, int $inputTokens, int $outputTokens, int $cachedTokens): string
    {
        $cached = max(0, min($cachedTokens, $inputTokens));
        $billableInput = max(0, $inputTokens - $cached);
        $output = max(0, $outputTokens);

        $inputRate = DecimalMath::toScaled((string) $price->input_per_million, self::RATE_SCALE);
        $outputRate = DecimalMath::toScaled((string) $price->output_per_million, self::RATE_SCALE);
        $cachedRate = $price->cached_input_per_million === null
            ? $inputRate
            : DecimalMath::toScaled((string) $price->cached_input_per_million, self::RATE_SCALE);
        $perRequest = DecimalMath::toScaled((string) $price->per_request, self::RATE_SCALE);

        // tokens × (rate per million at scale 8) = amount at scale 14.
        $amount = $billableInput * $inputRate + $cached * $cachedRate + $output * $outputRate;
        $amount += DecimalMath::rescale($perRequest, self::RATE_SCALE, self::PRODUCT_SCALE);

        return DecimalMath::format(
            DecimalMath::rescale($amount, self::PRODUCT_SCALE, self::LEDGER_SCALE),
            self::LEDGER_SCALE,
        );
    }

    private function legacyAmount(UsageRecord $record): string
    {
        $legacy = $this->legacy->cost($record->dimension, $record->quantity, $record->inputUnits, $record->outputUnits);

        return number_format(round((float) $legacy['cost'], self::LEDGER_SCALE), self::LEDGER_SCALE, '.', '');
    }
}
