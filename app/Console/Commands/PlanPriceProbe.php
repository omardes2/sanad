<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PlanPriceVersionSource;
use App\Exceptions\Billing\PlanPriceOverlapException;
use App\Exceptions\Billing\StalePlanPriceVersionException;
use App\Models\Plan;
use App\Services\Billing\PlanPriceBook;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Testing-only probe: applies ONE financial change to a plan (new price) from
 * the given expected open version id ("none" when the plan had no version),
 * printing "versioned", "stale" or "rejected". Launched as many concurrent OS
 * processes by the PostgreSQL concurrency test to prove that of N admins
 * editing from the same version exactly one wins. Not for production.
 */
class PlanPriceProbe extends Command
{
    protected $signature = 'sanad:plan-price-probe {plan} {price} {expected}';

    protected $description = 'Testing only: change a plan price and record its version';

    protected $hidden = true;

    public function handle(PlanPriceBook $book): int
    {
        $expectedArg = (string) $this->argument('expected');
        $expected = $expectedArg === 'none' ? null : (int) $expectedArg;

        try {
            DB::transaction(function () use ($book, $expected): void {
                $plan = Plan::query()->whereKey((int) $this->argument('plan'))->lockForUpdate()->firstOrFail();
                $book->assertOpenVersionIs($plan->id, $expected);
                $plan->forceFill(['price' => (string) $this->argument('price')])->save();
                $book->recordVersion($plan, CarbonImmutable::now(), PlanPriceVersionSource::Admin, null, $expected, true);
            });
            $this->line('versioned');
        } catch (StalePlanPriceVersionException) {
            $this->line('stale');
        } catch (PlanPriceOverlapException) {
            $this->line('rejected');
        }

        return self::SUCCESS;
    }
}
