<?php

declare(strict_types=1);

namespace App\Services\Ai\Catalog;

use App\Data\Ai\Catalog\ModelSpec;
use App\Data\Ai\Catalog\RouteEvaluation;
use App\Data\Ai\Catalog\RoutingContext;
use App\Enums\AiOperation;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Services\Ai\SanadAiRouter;

/**
 * Answers "what would the router choose?" WITHOUT writing anything (Phase C2).
 *
 *  - current(): the live evaluation, optionally under a different preferred
 *    provider or cost guardrail (the routing page's what-if controls).
 *  - proposed(): the evaluation the router would produce if the given
 *    provider/model attributes (is_enabled, priority) were changed — used by
 *    CatalogAdmin to block a change that leaves `chat` with no candidate and
 *    to demand a typed confirmation when the selected route would change.
 *    Phase C4 adds what-if overrides for the preferred provider and the
 *    catalog source ('config' / 'database'), so a catalog-source or routing
 *    cutover is previewed with the exact router before anything is written.
 *
 * proposed() follows the catalog-source setting exactly like the resolver:
 * `config` → database rows never influence routing, so the config catalog is
 * evaluated; `database` → the (proposed) database rows; `auto` → the database
 * rows when at least one enabled model of an enabled provider would remain,
 * otherwise the config catalog — so enabling the very first model, or
 * disabling the last one, is simulated as the source switch it really is.
 */
class RoutingSimulator
{
    public function __construct(
        private readonly SanadAiRouter $router,
        private readonly CatalogSourceResolver $resolver,
        private readonly ConfigCatalogSource $config,
    ) {}

    public function current(AiOperation $operation = AiOperation::Chat, ?string $preferredProvider = null, ?float $maxUnitCost = null): RouteEvaluation
    {
        return $this->router->evaluate($operation, new RoutingContext(preferredProvider: $preferredProvider, maxUnitCost: $maxUnitCost));
    }

    /**
     * @param  array<int, array{is_enabled?: bool, priority?: int}>  $providerOverrides  provider id => proposed attributes
     * @param  array<int, array{is_enabled?: bool, priority?: int}>  $modelOverrides  model id => proposed attributes
     */
    /**
     * @param  string|null  $preferredProvider  what-if preference (Phase C4 primary / mode cutover); null = live preference
     * @param  string|null  $catalogSource  what-if catalog source 'config' | 'database'; null = the live mode
     */
    public function proposed(array $providerOverrides = [], array $modelOverrides = [], AiOperation $operation = AiOperation::Chat, ?string $preferredProvider = null, ?string $catalogSource = null): RouteEvaluation
    {
        $context = new RoutingContext(preferredProvider: $preferredProvider);
        $mode = $catalogSource ?? $this->resolver->mode();

        if ($mode === 'config') {
            return $this->router->evaluate($operation, $context, $this->config->candidates($operation, $context));
        }

        $all = $this->specsFromDatabase($providerOverrides, $modelOverrides);

        if ($mode === 'auto' && $all === []) {
            return $this->router->evaluate($operation, $context, $this->config->candidates($operation, $context));
        }

        $specs = array_values(array_filter($all, static fn (ModelSpec $spec): bool => $spec->supports($operation)));

        return $this->router->evaluate($operation, $context, $specs);
    }

    /**
     * The database catalog as ModelSpecs, with the proposed attributes applied
     * in memory — same construction rules as DatabaseCatalogSource (every
     * enabled model of every enabled provider, any operation; provider
     * priority first, then model priority).
     *
     * @param  array<int, array{is_enabled?: bool, priority?: int}>  $providerOverrides
     * @param  array<int, array{is_enabled?: bool, priority?: int}>  $modelOverrides
     * @return list<ModelSpec>
     */
    private function specsFromDatabase(array $providerOverrides, array $modelOverrides): array
    {
        $providers = AiProvider::query()->orderBy('id')->get();
        $models = AiModel::query()->with('fallback.provider')->orderBy('id')->get();

        foreach ($providers as $provider) {
            foreach ($providerOverrides[$provider->id] ?? [] as $attribute => $value) {
                $provider->setAttribute($attribute, $value);
            }
        }

        foreach ($models as $model) {
            foreach ($modelOverrides[$model->id] ?? [] as $attribute => $value) {
                $model->setAttribute($attribute, $value);
            }
        }

        $providers = $providers->sortBy([['priority', 'desc'], ['id', 'asc']]);
        $models = $models->sortBy([['priority', 'desc'], ['id', 'asc']]);
        $specs = [];

        foreach ($providers as $provider) {
            if (! $provider->is_enabled) {
                continue;
            }

            foreach ($models->where('provider_id', $provider->id) as $model) {
                if (! $model->is_enabled) {
                    continue;
                }

                $fallback = $model->fallback;

                $specs[] = new ModelSpec(
                    provider: $provider->key,
                    model: $model->external_id,
                    capabilities: $model->operations(),
                    enabled: true,
                    priority: (int) $provider->priority,
                    fallbackModel: $fallback?->external_id,
                    fallbackProvider: $fallback?->provider?->key,
                    modelId: $model->id,
                    providerId: $provider->id,
                );
            }
        }

        return $specs;
    }
}
