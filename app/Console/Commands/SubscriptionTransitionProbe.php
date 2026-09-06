<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\Billing\StaleSubscriptionStateException;
use App\Models\Subscription;
use App\Services\Billing\SubscriptionService;
use Illuminate\Console\Command;

/**
 * Testing-only probe: applies ONE admin transition (suspend | activate |
 * extend) with the given expected state token and prints "ok:<status>" or
 * "stale". Launched concurrently by the PostgreSQL concurrency test to prove
 * that of N admins acting on the same viewed state exactly one wins.
 */
class SubscriptionTransitionProbe extends Command
{
    protected $signature = 'sanad:subscription-transition-probe {subscription} {action} {expected}';

    protected $description = 'Testing only: apply one subscription transition and print the status';

    protected $hidden = true;

    public function handle(SubscriptionService $service): int
    {
        $subscription = Subscription::query()->findOrFail((int) $this->argument('subscription'));

        $expected = (string) $this->argument('expected');

        try {
            $result = match ((string) $this->argument('action')) {
                'suspend' => $service->suspend($subscription, $expected),
                'activate' => $service->activate($subscription, $expected),
                'extend' => $service->extend($subscription, 1, $expected),
                default => throw new \InvalidArgumentException('Unknown action'),
            };
        } catch (StaleSubscriptionStateException) {
            $this->line('stale');

            return self::SUCCESS;
        }

        $this->line('ok:'.$result->status->value);

        return self::SUCCESS;
    }
}
