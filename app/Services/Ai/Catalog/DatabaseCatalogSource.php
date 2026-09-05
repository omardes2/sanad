<?php

declare(strict_types=1);

namespace App\Services\Ai\Catalog;

use App\Contracts\Ai\CatalogSource;
use App\Data\Ai\Catalog\ModelSpec;
use App\Data\Ai\Catalog\RoutingContext;
use App\Enums\AiOperation;
use App\Models\AiModel;
use App\Models\AiProvider;
use Illuminate\Support\Facades\Schema;

/**
 * Database-backed catalog (Phase B2): enabled providers × enabled models, as
 * ModelSpec values the router already understands.
 *
 * Ordering: provider priority, then model priority (then id). A spec's
 * `priority` is the PROVIDER priority; the router's stable sort keeps the
 * model order within a provider. The router still ranks the preferred provider
 * (AI_PROVIDER) first — `is_primary` is stored but not applied in B2.
 *
 * The whole catalog is cached briefly (CatalogCache); any save to a provider,
 * model or price invalidates it.
 */
final class DatabaseCatalogSource implements CatalogSource
{
    /**
     * Whether the database catalog can serve at all: the tables exist and at
     * least one enabled model of an enabled provider is present.
     */
    public function isAvailable(): bool
    {
        return CatalogCache::remember('available', static function (): bool {
            if (! Schema::hasTable('ai_providers') || ! Schema::hasTable('ai_models')) {
                return false;
            }

            return AiModel::query()
                ->where('is_enabled', true)
                ->whereHas('provider', static fn ($q) => $q->where('is_enabled', true))
                ->exists();
        });
    }

    public function candidates(AiOperation $operation, RoutingContext $context): array
    {
        $specs = array_values(array_filter(
            $this->allSpecs(),
            static fn (ModelSpec $spec): bool => $spec->supports($operation),
        ));

        return $specs;
    }

    /**
     * Every enabled model of every enabled provider, best first.
     *
     * @return list<ModelSpec>
     */
    public function allSpecs(): array
    {
        return CatalogCache::remember('specs', static function (): array {
            if (! Schema::hasTable('ai_providers') || ! Schema::hasTable('ai_models')) {
                return [];
            }

            $providers = AiProvider::query()
                ->where('is_enabled', true)
                ->orderByDesc('priority')
                ->orderBy('id')
                ->get()
                ->keyBy('id');

            if ($providers->isEmpty()) {
                return [];
            }

            $models = AiModel::query()
                ->with('fallback.provider')
                ->whereIn('provider_id', $providers->keys()->all())
                ->where('is_enabled', true)
                ->orderByDesc('priority')
                ->orderBy('id')
                ->get();

            $specs = [];

            foreach ($providers as $provider) {
                foreach ($models->where('provider_id', $provider->id) as $model) {
                    $fallback = $model->fallback;

                    $specs[] = new ModelSpec(
                        provider: $provider->key,
                        model: $model->external_id,
                        capabilities: $model->operations(),
                        enabled: true,
                        priority: $provider->priority,
                        fallbackModel: $fallback?->external_id,
                        fallbackProvider: $fallback?->provider?->key,
                        modelId: $model->id,
                        providerId: $provider->id,
                    );
                }
            }

            return $specs;
        });
    }
}
