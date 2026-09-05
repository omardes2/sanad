<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AiOperation;
use App\Models\AiModel;
use App\Models\AiProvider;
use Illuminate\Database\Seeder;

/**
 * LOCAL / TESTING ONLY: registers the two built-in providers and their
 * configured models in the database catalog, without any price, so the
 * database-backed catalog can be exercised in development. Production uses
 * the explicit, dry-run-first `sanad:ai:bootstrap` command instead — this
 * seeder is never run automatically outside local/testing (see DatabaseSeeder).
 */
class AiCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $preferred = (string) config('ai.provider', 'groq');

        foreach (['openai', 'groq'] as $key) {
            $model = trim((string) config("ai.providers.{$key}.model", ''));

            $provider = AiProvider::query()->firstOrCreate(['key' => $key], [
                'name' => ucfirst($key),
                'driver' => $key,
                'credentials_ref' => strtoupper($key).'_API_KEY',
                'capabilities' => [AiOperation::Chat->value],
                'is_enabled' => true,
                'is_primary' => false,
                'priority' => $key === $preferred ? 100 : 10,
            ]);

            if ($model === '') {
                continue;
            }

            AiModel::query()->firstOrCreate(
                ['provider_id' => $provider->id, 'external_id' => $model],
                [
                    'name' => $model,
                    'aliases' => [],
                    'capabilities' => [AiOperation::Chat->value],
                    'supports_tools' => true,
                    'is_enabled' => true,
                    'priority' => 0,
                ],
            );
        }
    }
}
