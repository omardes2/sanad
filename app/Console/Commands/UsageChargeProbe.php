<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UsageDimension;
use App\Models\User;
use App\Services\Billing\UsageEngine;
use Illuminate\Console\Command;

/**
 * Testing-only probe: performs exactly ONE AiReply charge for a subscriber and
 * prints the outcome. Launched as many concurrent OS processes by the real
 * PostgreSQL concurrency test to prove the atomic upsert never exceeds the cap.
 * Not intended for production use.
 */
class UsageChargeProbe extends Command
{
    protected $signature = 'sanad:usage-charge-probe {subscriber} {key}';

    protected $description = 'Testing only: perform one usage charge and print the outcome';

    protected $hidden = true;

    public function handle(UsageEngine $engine): int
    {
        config(['billing.enforce' => true]);

        $subscriber = User::find((int) $this->argument('subscriber'));

        if ($subscriber === null) {
            $this->line('missing');

            return self::FAILURE;
        }

        $decision = $engine->charge($subscriber, UsageDimension::AiReply, (string) $this->argument('key'));

        // Single machine-readable token for the parent test to tally.
        $this->line($decision->outcome->value);

        return self::SUCCESS;
    }
}
