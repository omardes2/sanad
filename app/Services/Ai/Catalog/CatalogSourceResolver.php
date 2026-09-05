<?php

declare(strict_types=1);

namespace App\Services\Ai\Catalog;

use App\Contracts\Ai\CatalogSource;
use App\Data\Ai\Catalog\RoutingContext;
use App\Enums\AiOperation;
use App\Services\Settings\SettingsRepository;

/**
 * Chooses which catalog the router reads, by the `ai.catalog_source` setting
 * (env override AI_CATALOG_SOURCE > database value > config default):
 *
 *  - "auto" (default): the database catalog when it has at least one enabled
 *    model, otherwise the config catalog — so a deployment with empty B2
 *    tables routes EXACTLY as before (Groq stays first);
 *  - "database": always the database catalog;
 *  - "config": always the config catalog — the instant rollback switch.
 */
final class CatalogSourceResolver implements CatalogSource
{
    public function __construct(
        private readonly DatabaseCatalogSource $database,
        private readonly ConfigCatalogSource $config,
        private readonly SettingsRepository $settings,
    ) {}

    public function candidates(AiOperation $operation, RoutingContext $context): array
    {
        return $this->active()->candidates($operation, $context);
    }

    /**
     * Which source is in effect right now: "database" or "config".
     */
    public function activeName(): string
    {
        return $this->active() === $this->database ? 'database' : 'config';
    }

    public function mode(): string
    {
        $mode = strtolower(trim((string) $this->settings->get('ai.catalog_source')));

        return in_array($mode, ['auto', 'database', 'config'], true) ? $mode : 'auto';
    }

    private function active(): CatalogSource
    {
        return match ($this->mode()) {
            'database' => $this->database,
            'config' => $this->config,
            default => $this->database->isAvailable() ? $this->database : $this->config,
        };
    }
}
