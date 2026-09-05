<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Contracts\Ai\CatalogSource;
use App\Data\Ai\Catalog\ModelSpec;
use App\Data\Ai\Catalog\ResolvedRoute;
use App\Data\Ai\Catalog\RoutingContext;
use App\Enums\AiOperation;
use App\Exceptions\Ai\AiConfigurationException;

/**
 * Sanad AI Router — chooses which (provider, model) serves an operation.
 *
 * Selection policy (Phase A): candidates come from the CatalogSource; the
 * preferred provider (context, else config('ai.provider')) wins ties, then
 * catalog priority; a candidate is skipped when it is disabled, its provider is
 * unknown, unconfigured (no credentials), or does not support the operation.
 * Later phases extend the policy with plan, cost estimate, profitability and
 * internal cost guardrails through RoutingContext — the callers do not change,
 * and swapping the primary model is a catalog (data) change, not code.
 */
class SanadAiRouter
{
    public function __construct(
        private readonly AiManager $manager,
        private readonly CatalogSource $catalog,
    ) {}

    /**
     * @throws AiConfigurationException when no configured provider can serve the operation
     */
    public function route(AiOperation $operation, ?RoutingContext $context = null): ResolvedRoute
    {
        $context ??= new RoutingContext;
        $preferred = $context->preferredProvider ?? (string) config('ai.provider', 'groq');

        $candidates = $this->catalog->candidates($operation, $context);

        usort(
            $candidates,
            static fn (ModelSpec $a, ModelSpec $b): int => [$b->provider === $preferred, $b->priority]
                <=> [$a->provider === $preferred, $a->priority],
        );

        foreach ($candidates as $index => $spec) {
            if (! $spec->enabled || ! $this->manager->has($spec->provider)) {
                continue;
            }

            $provider = $this->manager->provider($spec->provider);

            if (! $provider->supports($operation) || ! $provider->isConfigured()) {
                continue;
            }

            return new ResolvedRoute(
                provider: $provider,
                model: $spec->model,
                spec: $spec,
                alternatives: array_values(array_slice($candidates, $index + 1)),
            );
        }

        throw AiConfigurationException::noRoute($operation);
    }
}
