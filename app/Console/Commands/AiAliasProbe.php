<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\Ai\CatalogValidationException;
use App\Services\Ai\Catalog\CatalogAdmin;
use Illuminate\Console\Command;

/**
 * Testing-only probe: creates ONE disabled model through CatalogAdmin and
 * prints "created" or "rejected". Launched as many concurrent OS processes by
 * the real PostgreSQL concurrency test to prove the provider-row lock makes
 * external_id / alias uniqueness hold under a race. Not for production use.
 */
class AiAliasProbe extends Command
{
    protected $signature = 'sanad:ai-alias-probe {provider} {external_id} {alias?}';

    protected $description = 'Testing only: create one model with an alias and print the outcome';

    protected $hidden = true;

    public function handle(CatalogAdmin $admin): int
    {
        try {
            $admin->createModel([
                'provider_id' => (int) $this->argument('provider'),
                'external_id' => (string) $this->argument('external_id'),
                'name' => (string) $this->argument('external_id'),
                'aliases' => $this->argument('alias') !== null ? [(string) $this->argument('alias')] : [],
                'capabilities' => ['chat'],
                'supports_tools' => false,
                'priority' => 0,
                'is_enabled' => false,
            ]);
            $this->line('created');
        } catch (CatalogValidationException) {
            $this->line('rejected');
        }

        return self::SUCCESS;
    }
}
