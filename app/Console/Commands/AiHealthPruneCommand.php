<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ProviderHealthCheck;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class AiHealthPruneCommand extends Command
{
    protected $signature = 'sanad:ai:health:prune {--days= : Retention in days (default ai.health.retention_days)}';

    protected $description = 'Delete provider health history older than the retention window';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('ai.health.retention_days', 90));
        $deleted = ProviderHealthCheck::query()->where('checked_at', '<', CarbonImmutable::now()->subDays(max(1, $days)))->delete();
        $this->info("Pruned {$deleted} health check(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
