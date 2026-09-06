<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PlanPriceVersionSource;
use App\Exceptions\Billing\PlanPriceOverlapException;
use App\Models\Plan;
use App\Services\Billing\PlanPriceBook;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Testing-only probe: applies ONE financial change to a plan (new price) and
 * records its price version, printing "versioned" or "rejected". Launched as
 * many concurrent OS processes by the PostgreSQL concurrency test to prove the
 * parent-row lock serialises versions with no overlap. Not for production.
 */
class PlanPriceProbe extends Command
{
    protected $signature = 'sanad:plan-price-probe {plan} {price}';

    protected $description = 'Testing only: change a plan price and record its version';

    protected $hidden = true;

    public function handle(PlanPriceBook $book): int
    {
        try {
            DB::transaction(function () use ($book): void {
                $plan = Plan::query()->whereKey((int) $this->argument('plan'))->lockForUpdate()->firstOrFail();
                $plan->forceFill(['price' => (string) $this->argument('price')])->save();
                $book->recordVersion($plan, CarbonImmutable::now(), PlanPriceVersionSource::Admin);
            });
            $this->line('versioned');
        } catch (PlanPriceOverlapException) {
            $this->line('rejected');
        }

        return self::SUCCESS;
    }
}
