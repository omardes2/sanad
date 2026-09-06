<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use App\Models\Plan;
use App\Models\PlanPriceVersion;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * A new plan price version would overlap an existing one (or start at/before
 * the open version's start). Plan price history is never rewritten, split or
 * back-dated.
 */
final class PlanPriceOverlapException extends RuntimeException
{
    public static function for(Plan $plan, PlanPriceVersion $existing, CarbonImmutable $from): self
    {
        $oldPeriod = $existing->effective_from?->toIso8601String().' → '.($existing->effective_until?->toIso8601String() ?? 'open');

        return new self(
            "Plan price version starting at [{$from->toIso8601String()}] for plan [{$plan->slug}] overlaps version #{$existing->id} [{$oldPeriod}]. "
            .'Plan price history is never rewritten, split or back-dated.'
        );
    }
}
