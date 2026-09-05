<?php

declare(strict_types=1);

namespace App\Data\Ai\Catalog;

use App\Contracts\Ai\AiProvider;

/**
 * The router's decision: a configured provider instance, the model to request,
 * the catalog entry it came from, and the remaining candidates in order — so a
 * caller can fall back to the next one (used by later phases).
 *
 * @param  list<ModelSpec>  $alternatives
 */
final readonly class ResolvedRoute
{
    /**
     * @param  list<ModelSpec>  $alternatives
     */
    public function __construct(
        public AiProvider $provider,
        public string $model,
        public ModelSpec $spec,
        public array $alternatives = [],
    ) {}
}
