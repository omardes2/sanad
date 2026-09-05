<?php

declare(strict_types=1);

namespace App\Services\Ai\Catalog;

use App\Models\AiModel;
use App\Models\AiProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Maps what a provider REPORTS back to a catalog row (alias resolution):
 *
 *  1. exact match on ai_models.external_id;
 *  2. the reported id is one of the model's `aliases` (dated snapshots such as
 *     gpt-4.1-mini-2025-04-14);
 *  3. last resort: the id the router REQUESTED (routedModel).
 *
 * Disabled models still resolve — a cost is attributed to the model that
 * actually served the request. Returns null when the provider or model is
 * not in the catalog (the ledger row is then unpriced, never guessed).
 */
class ModelResolver
{
    public function resolve(string $providerKey, ?string $reportedModel, ?string $routedModel = null): ?AiModel
    {
        $models = $this->modelsOf($providerKey);

        if ($models->isEmpty()) {
            return null;
        }

        foreach ([$reportedModel, $routedModel] as $candidate) {
            $candidate = trim((string) $candidate);

            if ($candidate === '') {
                continue;
            }

            $match = $models->first(static fn (AiModel $model): bool => $model->external_id === $candidate)
                ?? $models->first(static fn (AiModel $model): bool => $model->matches($candidate));

            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    /**
     * @return Collection<int, AiModel>
     */
    private function modelsOf(string $providerKey): Collection
    {
        return CatalogCache::remember('models.'.$providerKey, static function () use ($providerKey): Collection {
            if (! Schema::hasTable('ai_models')) {
                return new Collection;
            }

            $provider = AiProvider::query()->where('key', $providerKey)->first();

            if ($provider === null) {
                return new Collection;
            }

            return AiModel::query()->where('provider_id', $provider->id)->orderBy('id')->get();
        });
    }
}
