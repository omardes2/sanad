<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\UsageDimension;

/**
 * Computes the recorded service cost of a usage event from CONFIGURABLE rates
 * (config/billing.cost_rates) — never hard-coded Groq/Meta prices. Defaults are
 * zero so nothing is invented; real rates are set via env when known. This is
 * the foundation for revenue − cost = margin, not a full accounting system.
 */
class UsageCostCalculator
{
    /**
     * @return array{cost: float, currency: string}
     */
    public function cost(UsageDimension $dimension, int $quantity, int $inputTokens = 0, int $outputTokens = 0): array
    {
        $currency = (string) config('billing.cost_currency', 'USD');
        $rates = (array) config('billing.cost_rates', []);
        $entry = $rates[$dimension->value] ?? null;

        if (! is_array($entry)) {
            return ['cost' => 0.0, 'currency' => $currency];
        }

        $rate = (float) ($entry['rate'] ?? 0);
        $unit = (string) ($entry['unit'] ?? 'event');

        $cost = match ($unit) {
            'token_k' => $rate * (($inputTokens + $outputTokens) / 1000),
            default => $rate * $quantity, // "event"
        };

        return ['cost' => round($cost, 6), 'currency' => $currency];
    }
}
