<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AiOperation;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Services\Ai\AiManager;
use App\Services\Ai\Catalog\CatalogCache;
use App\Services\Audit\AuditLogger;
use App\Support\Audit\AuditActions;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;

/**
 * Safe, explicit bootstrap of the database catalog (Phase B2) — the opposite
 * of a blind seeder:
 *
 *  - DRY-RUN by default: prints exactly what would be created; nothing is
 *    written without --apply (and, in production, a confirmation / --force).
 *  - Idempotent: providers by key, models by (provider, external_id); existing
 *    rows are left untouched unless --update-metadata is given.
 *  - Derives providers and models from the CURRENT config (AI_PROVIDER,
 *    OPENAI_MODEL, GROQ_MODEL, ...) or from explicit --model=provider:id
 *    options — it never imposes a commercial model choice.
 *  - NEVER writes a price. Prices are published only through sanad:ai:price
 *    with values reviewed by a human.
 *  - Audited (Phase C2): the applied plan is recorded in the same transaction.
 *  - Never changes the provider production uses: is_primary stays false and
 *    AI_PROVIDER remains the router's preference in B2.
 */
class AiBootstrapCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'sanad:ai:bootstrap
        {--apply : Write the changes (default is a dry run)}
        {--model=* : Explicit model as provider:external_id (repeatable); defaults to each configured provider\'s model}
        {--update-metadata : Also refresh name/capabilities of rows that already exist}
        {--force : Skip the production confirmation prompt}';

    protected $description = 'Register AI providers/models in the database catalog from config or explicit options (dry run by default, never writes prices)';

    public function handle(AiManager $manager, AuditLogger $audit): int
    {
        $apply = (bool) $this->option('apply');

        if ($apply && ! $this->confirmToProceed('Application is in production — write to the AI catalog?')) {
            return self::FAILURE;
        }

        $preferred = (string) config('ai.provider', 'groq');
        $plan = $this->plan($manager, $preferred);

        if ($plan === []) {
            $this->warn('Nothing to bootstrap: no configured provider has a model, and no --model was given.');

            return self::SUCCESS;
        }

        $this->line($apply ? '<info>Applying</info> catalog bootstrap:' : '<comment>Dry run</comment> — nothing will be written (add --apply):');
        $this->table(
            ['Kind', 'Handle', 'Action', 'Details'],
            array_map(static fn (array $row): array => [$row['kind'], $row['handle'], $row['action'], $row['details']], $plan),
        );
        $this->line('Prices are never written by this command; publish them explicitly with sanad:ai:price.');

        if (! $apply) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($plan, $audit): void {
            $providers = [];

            foreach ($plan as $row) {
                if ($row['kind'] !== 'provider') {
                    continue;
                }

                $providers[$row['key']] = $this->writeProvider($row);
            }

            foreach ($plan as $row) {
                if ($row['kind'] !== 'model') {
                    continue;
                }

                $this->writeModel($row, $providers[$row['provider']] ?? AiProvider::query()->where('key', $row['provider'])->firstOrFail());
            }

            // Same transaction as the writes: no audit row without the change,
            // no change without its audit row (Phase C2).
            $audit->record(AuditActions::AiCatalogBootstrapApplied, null, [], [
                'plan' => array_map(static fn (array $row): array => [
                    'kind' => $row['kind'], 'handle' => $row['handle'], 'action' => $row['action'],
                ], $plan),
                'update_metadata' => (bool) $this->option('update-metadata'),
            ]);
        });

        CatalogCache::flush();
        $this->info('Catalog bootstrap applied.');

        return self::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function plan(AiManager $manager, string $preferred): array
    {
        $wanted = $this->wantedModels($manager);
        $plan = [];

        foreach ($wanted as $providerKey => $models) {
            $existingProvider = AiProvider::query()->where('key', $providerKey)->first();
            $priority = $providerKey === $preferred ? 100 : 10;

            $plan[] = [
                'kind' => 'provider',
                'key' => $providerKey,
                'handle' => $providerKey,
                'action' => $existingProvider === null ? 'create' : ($this->option('update-metadata') ? 'update-metadata' : 'keep'),
                'details' => "priority={$priority}, enabled, is_primary=false, credentials from env",
                'priority' => $priority,
            ];

            foreach ($models as $externalId) {
                $existingModel = $existingProvider === null
                    ? null
                    : AiModel::query()->where('provider_id', $existingProvider->id)->where('external_id', $externalId)->first();

                $plan[] = [
                    'kind' => 'model',
                    'provider' => $providerKey,
                    'external_id' => $externalId,
                    'handle' => "{$providerKey}:{$externalId}",
                    'action' => $existingModel === null ? 'create' : ($this->option('update-metadata') ? 'update-metadata' : 'keep'),
                    'details' => 'capabilities=chat, supports_tools=true, enabled, no price',
                ];
            }
        }

        return $plan;
    }

    /**
     * provider key => list of external model ids.
     *
     * @return array<string, list<string>>
     */
    private function wantedModels(AiManager $manager): array
    {
        $wanted = [];

        /** @var list<string> $explicit */
        $explicit = (array) $this->option('model');

        if ($explicit !== []) {
            foreach ($explicit as $handle) {
                [$provider, $model] = array_pad(explode(':', (string) $handle, 2), 2, '');
                $provider = trim($provider);
                $model = trim($model);

                if ($provider === '' || $model === '' || ! $manager->has($provider)) {
                    $this->error("Invalid --model [{$handle}]: expected provider:external_id with a known provider (".implode(', ', $manager->names()).').');

                    continue;
                }

                $wanted[$provider][] = $model;
            }

            return array_map(static fn (array $models): array => array_values(array_unique($models)), $wanted);
        }

        foreach ($manager->names() as $key) {
            $model = trim((string) config("ai.providers.{$key}.model", ''));

            if ($model !== '') {
                $wanted[$key] = [$model];
            }
        }

        return $wanted;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function writeProvider(array $row): AiProvider
    {
        $attributes = [
            'name' => ucfirst((string) $row['key']),
            'driver' => (string) $row['key'],
            'capabilities' => [AiOperation::Chat->value],
            'credentials_ref' => strtoupper((string) $row['key']).'_API_KEY',
        ];

        $provider = AiProvider::query()->where('key', $row['key'])->first();

        if ($provider === null) {
            return AiProvider::query()->create($attributes + [
                'key' => $row['key'],
                'is_enabled' => true,
                'is_primary' => false,
                'priority' => (int) $row['priority'],
            ]);
        }

        if ($row['action'] === 'update-metadata') {
            $provider->fill($attributes)->save();
        }

        return $provider;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function writeModel(array $row, AiProvider $provider): void
    {
        $attributes = [
            'name' => (string) $row['external_id'],
            'capabilities' => [AiOperation::Chat->value],
            'supports_tools' => true,
        ];

        $model = AiModel::query()->where('provider_id', $provider->id)->where('external_id', $row['external_id'])->first();

        if ($model === null) {
            AiModel::query()->create($attributes + [
                'provider_id' => $provider->id,
                'external_id' => $row['external_id'],
                'aliases' => [],
                'is_enabled' => true,
                'priority' => 0,
            ]);

            return;
        }

        if ($row['action'] === 'update-metadata') {
            $model->fill($attributes)->save();
        }
    }
}
