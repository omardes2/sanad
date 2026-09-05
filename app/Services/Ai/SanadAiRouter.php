<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Contracts\Ai\CatalogSource;
use App\Data\Ai\Catalog\ModelSpec;
use App\Data\Ai\Catalog\ResolvedRoute;
use App\Data\Ai\Catalog\RoutingContext;
use App\Enums\AiOperation;
use App\Exceptions\Ai\AiConfigurationException;
use App\Services\Billing\Pricing\CostEstimator;

/**
 * Sanad AI Router — chooses which (provider, model) serves an operation.
 *
 * Selection policy: candidates come from the CatalogSource (config or database,
 * see CatalogSourceResolver); the preferred provider — RoutingContext, else
 * config('ai.provider') / AI_PROVIDER — wins ties, then catalog priority; a
 * candidate is skipped when it is disabled, its provider is unknown,
 * unconfigured (no credentials), does not support the operation, or (cost
 * guardrail foundation, Phase B2) its KNOWN estimated cost exceeds the
 * context's maxUnitCost. The chosen model's declared fallback, when present
 * among the remaining candidates, is placed first in `alternatives`.
 *
 * In Phase B2 the operational preference deliberately stays AI_PROVIDER; the
 * database `is_primary` flag is stored for the Phase C cutover and not read
 * here, so B2 cannot change which provider production uses.
 */
class SanadAiRouter
{
    public function __construct(
        private readonly AiManager $manager,
        private readonly CatalogSource $catalog,
        private readonly CostEstimator $estimator,
    ) {}

    /**
     * @throws AiConfigurationException when no configured provider can serve the operation
     */
    public function route(AiOperation $operation, ?RoutingContext $context = null): ResolvedRoute
    {
        $context ??= new RoutingContext;
        $preferred = $context->preferredProvider ?? (string) config('ai.provider', 'groq');

        $candidates = $this->catalog->candidates($operation, $context);

        // Stable sort: within the same provider/priority the catalog's own
        // order (e.g. model priority in the database catalog) is preserved.
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

            if ($this->exceedsCostGuardrail($spec, $context)) {
                continue;
            }

            return new ResolvedRoute(
                provider: $provider,
                model: $spec->model,
                spec: $spec,
                alternatives: $this->alternatives($spec, array_slice($candidates, $index + 1)),
            );
        }

        throw AiConfigurationException::noRoute($operation);
    }

    /**
     * Only a KNOWN estimate can exceed the guardrail; unknown costs never skip.
     */
    private function exceedsCostGuardrail(ModelSpec $spec, RoutingContext $context): bool
    {
        if ($context->maxUnitCost === null) {
            return false;
        }

        $estimate = $this->estimator->estimate($spec);

        return $estimate !== null && $estimate > $context->maxUnitCost;
    }

    /**
     * Remaining candidates in order, with the chosen spec's declared fallback
     * moved to the front when it is among them.
     *
     * @param  list<ModelSpec>  $remaining
     * @return list<ModelSpec>
     */
    private function alternatives(ModelSpec $chosen, array $remaining): array
    {
        $remaining = array_values($remaining);

        foreach ($remaining as $index => $candidate) {
            if ($chosen->fallsBackTo($candidate)) {
                unset($remaining[$index]);

                return [$candidate, ...array_values($remaining)];
            }
        }

        return $remaining;
    }
}
