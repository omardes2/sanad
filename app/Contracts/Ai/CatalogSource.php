<?php

declare(strict_types=1);

namespace App\Contracts\Ai;

use App\Data\Ai\Catalog\ModelSpec;
use App\Data\Ai\Catalog\RoutingContext;
use App\Enums\AiOperation;

/**
 * Where the router gets its model catalog from. Phase A ships a config-backed
 * source (bootstrap defaults); a database-backed source managed from Sanad Admin
 * replaces it later by implementing this same contract — the router does not
 * change. The source only supplies ordered candidates; selection policy
 * (preference, availability, capability) lives in the router.
 */
interface CatalogSource
{
    /**
     * Models able to serve the operation, best first (highest priority first).
     *
     * @return list<ModelSpec>
     */
    public function candidates(AiOperation $operation, RoutingContext $context): array;
}
