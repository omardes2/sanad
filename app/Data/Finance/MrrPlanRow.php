<?php

declare(strict_types=1);

namespace App\Data\Finance;

/**
 * Current Calculated MRR of one (currency, plan) group, as of `asOf`.
 * mrrNormalized = monthly-equivalent list price × activeCount (a decimal
 * string, scale 6). trialing / past_due subscriptions are COUNTED but never
 * contribute to MRR; past_due is "billed, not paid" and must be shown apart.
 */
final readonly class MrrPlanRow
{
    public function __construct(
        public string $currency,
        public ?int $planId,
        public string $planKey,
        public ?string $planSlug,
        public ?string $planPrice,
        public ?string $billingPeriod,
        public int $activeCount,
        public int $trialingCount,
        public int $pastDueCount,
        public string $mrrNormalized,
    ) {}
}
