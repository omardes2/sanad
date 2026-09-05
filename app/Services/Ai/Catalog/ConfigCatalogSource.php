<?php

declare(strict_types=1);

namespace App\Services\Ai\Catalog;

use App\Contracts\Ai\CatalogSource;
use App\Data\Ai\Catalog\ModelSpec;
use App\Data\Ai\Catalog\RoutingContext;
use App\Enums\AiOperation;
use App\Services\Ai\Routing\RoutingPreference;

/**
 * Config-backed catalog (bootstrap defaults).
 *
 * Reads config('ai.catalog') when present. When it is empty — the common case
 * until the database-backed catalog lands — it derives one Chat-capable entry
 * per configured provider from config('ai.providers'), ranking the preferred
 * provider (config('ai.provider')) first. That keeps today's deployments
 * routing exactly as before (e.g. Groq) with no config change.
 */
final class ConfigCatalogSource implements CatalogSource
{
    public function __construct(private readonly RoutingPreference $preference) {}

    private const PREFERRED_PRIORITY = 100;

    private const DEFAULT_PRIORITY = 10;

    public function candidates(AiOperation $operation, RoutingContext $context): array
    {
        /** @var list<array<string, mixed>> $entries */
        $entries = array_values((array) config('ai.catalog', []));

        $specs = $entries !== [] ? $this->fromCatalog($entries) : $this->fromProviders();

        $specs = array_values(array_filter($specs, static fn (ModelSpec $spec): bool => $spec->supports($operation)));

        usort($specs, static fn (ModelSpec $a, ModelSpec $b): int => $b->priority <=> $a->priority);

        return $specs;
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<ModelSpec>
     */
    private function fromCatalog(array $entries): array
    {
        $specs = [];

        foreach ($entries as $entry) {
            $provider = trim((string) ($entry['provider'] ?? ''));
            $model = trim((string) ($entry['model'] ?? ''));

            if ($provider === '' || $model === '') {
                continue;
            }

            $capabilities = [];

            foreach ((array) ($entry['capabilities'] ?? ['chat']) as $capability) {
                $operation = AiOperation::tryFrom((string) $capability);

                if ($operation !== null) {
                    $capabilities[] = $operation;
                }
            }

            $specs[] = new ModelSpec(
                provider: $provider,
                model: $model,
                capabilities: $capabilities !== [] ? $capabilities : [AiOperation::Chat],
                enabled: (bool) ($entry['enabled'] ?? true),
                priority: (int) ($entry['priority'] ?? 0),
                fallbackModel: isset($entry['fallback_model']) ? (string) $entry['fallback_model'] : null,
            );
        }

        return $specs;
    }

    /**
     * @return list<ModelSpec>
     */
    private function fromProviders(): array
    {
        $preferred = $this->preference->preferredProvider();
        $specs = [];

        foreach ((array) config('ai.providers', []) as $key => $config) {
            $model = is_array($config) ? trim((string) ($config['model'] ?? '')) : '';

            if ($model === '') {
                continue;
            }

            $specs[] = new ModelSpec(
                provider: (string) $key,
                model: $model,
                capabilities: [AiOperation::Chat],
                enabled: true,
                priority: (string) $key === $preferred ? self::PREFERRED_PRIORITY : self::DEFAULT_PRIORITY,
            );
        }

        return $specs;
    }
}
