<?php

declare(strict_types=1);

namespace App\Data\Ai\Catalog;

use App\Models\User;

/**
 * What the router may consider when choosing a model. Phase A uses the
 * subscriber (for future plan/guardrail rules) and an optional provider
 * preference; Phase B2 adds the cost guardrail foundation: when maxUnitCost is
 * set, a candidate whose ESTIMATED cost per request is known and exceeds it is
 * skipped. Null (the default) means no cost constraint at all.
 */
final readonly class RoutingContext
{
    public function __construct(
        public ?User $user = null,
        public ?string $preferredProvider = null,
        public ?float $maxUnitCost = null,
    ) {}
}
