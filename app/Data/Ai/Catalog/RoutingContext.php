<?php

declare(strict_types=1);

namespace App\Data\Ai\Catalog;

use App\Models\User;

/**
 * What the router may consider when choosing a model. Phase A uses the
 * subscriber (for future plan/guardrail rules) and an optional provider
 * preference; later phases add plan, cost estimate and profitability status
 * here without changing the router's signature.
 */
final readonly class RoutingContext
{
    public function __construct(
        public ?User $user = null,
        public ?string $preferredProvider = null,
    ) {}
}
