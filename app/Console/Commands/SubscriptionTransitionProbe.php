<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\Billing\SubscriptionService;
use Illuminate\Console\Command;

/**
 * Testing-only probe: applies ONE transition (suspend | activate | extend) to a
 * subscription through SubscriptionService and prints the resulting status.
 * Launched concurrently by the PostgreSQL concurrency test to prove the row
 * lock serialises transitions and the event chain stays consistent.
 */
class SubscriptionTransitionProbe extends Command
{
    protected $signature = 'sanad:subscription-transition-probe {subscription} {action}';

    protected $description = 'Testing only: apply one subscription transition and print the status';

    protected $hidden = true;

    public function handle(SubscriptionService $service): int
    {
        $subscription = Subscription::query()->findOrFail((int) $this->argument('subscription'));

        $result = match ((string) $this->argument('action')) {
            'suspend' => $service->suspend($subscription),
            'activate' => $service->activate($subscription),
            'extend' => $service->extend($subscription, 1),
            default => throw new \InvalidArgumentException('Unknown action'),
        };

        $this->line($result->status->value);

        return self::SUCCESS;
    }
}
