<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\HealthCheckKind;
use App\Enums\HealthCheckTrigger;
use App\Models\AiProvider;
use App\Services\Ai\Health\ProviderHealthService;
use App\Services\Settings\SettingsRepository;
use Illuminate\Console\Command;
use Throwable;

/**
 * Scheduled health run (Phase C3): the NON-billable `auth` probe for every
 * enabled provider (adapters without one record skipped/unsupported). Never
 * an inference. Gated by the `ai.health.scheduled` setting unless --force.
 */
class AiHealthRunCommand extends Command
{
    protected $signature = 'sanad:ai:health:run
        {--force : Run even when ai.health.scheduled is off}';

    protected $description = 'Run the non-billable auth health probe for every enabled provider (scheduled; never inference)';

    public function handle(ProviderHealthService $health, SettingsRepository $settings): int
    {
        if (! $this->option('force') && ! $settings->get('ai.health.scheduled')) {
            $this->line('Scheduled health checks are disabled (ai.health.scheduled=false).');

            return self::SUCCESS;
        }

        foreach (AiProvider::query()->where('is_enabled', true)->orderBy('id')->get() as $provider) {
            try {
                $check = $health->run($provider, HealthCheckKind::Auth, HealthCheckTrigger::Scheduled);
                $this->line(sprintf('%s: %s%s', $provider->key, $check->status->value, $check->error_code ? ' ('.$check->error_code.')' : ''));
            } catch (Throwable $e) {
                $this->warn(sprintf('%s: not checked (%s)', $provider->key, $e::class));
            }
        }

        return self::SUCCESS;
    }
}
