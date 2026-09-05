<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Contracts\Ai\CatalogSource;
use App\Contracts\Ai\ReportsCredentialState;
use App\Data\Ai\Catalog\ModelSpec;
use App\Data\Ai\Catalog\ResolvedRoute;
use App\Data\Ai\Catalog\RouteEvaluation;
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
 * evaluate() exposes the same policy with a reason per candidate; route() is
 * evaluate() plus the exception. The admin routing page and the enable/
 * disable simulation (Phase C2) call evaluate() on real or hypothetical
 * candidate lists, so they can never disagree with production routing.
 *
 * The operational preference deliberately stays AI_PROVIDER; the database
 * `is_primary` flag is stored for the Phase C4 cutover and not read here.
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
        $evaluation = $this->evaluate($operation, $context);

        if ($evaluation->selected === null) {
            throw AiConfigurationException::noRoute($operation);
        }

        $spec = $evaluation->selected;
        $remaining = [];
        $seen = false;

        foreach ($evaluation->candidates as $row) {
            if ($seen) {
                $remaining[] = $row['spec'];
            } elseif ($row['spec'] === $spec) {
                $seen = true;
            }
        }

        return new ResolvedRoute(
            provider: $this->manager->provider($spec->provider),
            model: $spec->model,
            spec: $spec,
            alternatives: $this->alternatives($spec, $remaining),
        );
    }

    /**
     * Apply the selection policy to the catalog's candidates (or to an
     * explicit list) and explain every decision.
     *
     * @param  list<ModelSpec>|null  $candidates  hypothetical list; null = the live catalog
     */
    public function evaluate(AiOperation $operation, ?RoutingContext $context = null, ?array $candidates = null): RouteEvaluation
    {
        $context ??= new RoutingContext;
        $preferred = $context->preferredProvider ?? (string) config('ai.provider', 'groq');
        $candidates ??= $this->catalog->candidates($operation, $context);

        // Stable sort: within the same provider/priority the catalog's own
        // order (e.g. model priority in the database catalog) is preserved.
        usort(
            $candidates,
            static fn (ModelSpec $a, ModelSpec $b): int => [$b->provider === $preferred, $b->priority]
                <=> [$a->provider === $preferred, $a->priority],
        );

        $rows = [];
        $selected = null;

        foreach ($candidates as $spec) {
            [$status, $reason, $estimate] = $this->judge($spec, $operation, $context);

            if ($status === 'eligible' && $selected === null) {
                $status = 'selected';
                $selected = $spec;
            }

            $rows[] = ['spec' => $spec, 'status' => $status, 'reason' => $reason, 'estimate' => $estimate];
        }

        return new RouteEvaluation($preferred, $rows, $selected);
    }

    /**
     * @return array{0: string, 1: ?string, 2: ?float} status, reason, estimate
     */
    private function judge(ModelSpec $spec, AiOperation $operation, RoutingContext $context): array
    {
        if (! $spec->enabled) {
            return ['skipped', 'disabled', null];
        }

        if (! $spec->supports($operation)) {
            return ['skipped', 'unsupported_operation', null];
        }

        if (! $this->manager->has($spec->provider)) {
            return ['skipped', 'unknown_provider', null];
        }

        $provider = $this->manager->provider($spec->provider);

        if (! $provider->supports($operation)) {
            return ['skipped', 'provider_unsupported_operation', null];
        }

        if ($provider instanceof ReportsCredentialState && $provider->credentialFailure() !== null) {
            // Phase C3: the active vault credential could not be opened —
            // the provider is FAILED CLOSED, never silently on env.
            return ['skipped', 'credential_failed', null];
        }

        if (! $provider->isConfigured()) {
            return ['skipped', 'unconfigured', null];
        }

        $estimate = $context->maxUnitCost === null ? null : $this->estimator->estimate($spec);

        if ($context->maxUnitCost !== null && $estimate !== null && $estimate > $context->maxUnitCost) {
            return ['skipped', 'cost_guardrail', $estimate];
        }

        return ['eligible', null, $estimate];
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
