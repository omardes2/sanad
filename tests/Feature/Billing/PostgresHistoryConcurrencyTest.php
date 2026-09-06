<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\PlanPriceVersion;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\User;
use App\Support\Audit\AuditActions;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * GENUINE parallel tests for the Phase E0 financial history on PostgreSQL:
 *  - concurrent financial changes to ONE plan → versions serialised on the
 *    parent row lock: one open version, contiguous periods, no overlap;
 *  - concurrent baseline runs → one baseline per subscription, one open
 *    version per plan, one audit entry;
 *  - concurrent subscription transitions → row lock serialises them and the
 *    event chain (from_status == previous to_status) stays consistent.
 *
 * Runs only on a reachable pgsql connection. Not wrapped in RefreshDatabase;
 * it removes only the rows it created.
 */
beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Real concurrency test requires the pgsql connection.');
    }

    try {
        DB::connection()->getPdo();
    } catch (Throwable) {
        $this->markTestSkipped('PostgreSQL is not reachable.');
    }
});

function e0Plan(string $suffix): Plan
{
    return Plan::create(['name' => 'PG race', 'slug' => 'pg-race-'.$suffix.'-'.str()->random(5), 'price' => '10.00', 'currency' => 'USD', 'billing_period' => 'monthly', 'trial_days' => 0, 'limits' => [], 'features' => [], 'is_active' => true, 'is_default' => false, 'sort_order' => 99]);
}

function e0Cleanup(array $plans, array $users): void
{
    $planIds = collect($plans)->pluck('id');
    $subscriptionIds = Subscription::query()->whereIn('subscriber_id', collect($users)->pluck('id'))->pluck('id');
    $versionIds = PlanPriceVersion::query()->whereIn('plan_id', $planIds)->pluck('id');
    AuditLog::where('subject_type', (new PlanPriceVersion)->getMorphClass())->whereIn('subject_id', $versionIds)->delete();
    AuditLog::where('subject_type', (new Subscription)->getMorphClass())->whereIn('subject_id', $subscriptionIds)->delete();
    AuditLog::where('action', AuditActions::FinanceHistoryBaselineApplied)->delete();
    SubscriptionEvent::query()->whereIn('subscription_id', $subscriptionIds)->delete();
    PlanPriceVersion::query()->whereIn('plan_id', $planIds)->delete();
    foreach ($users as $user) {
        $user->delete();
    }
    Plan::query()->whereIn('id', $planIds)->delete();
}

function e0Run(array $args): Process
{
    $p = new Process(['php', 'artisan', ...$args], base_path());
    $p->start();

    return $p;
}

it('serialises concurrent financial changes on one plan: one open version, contiguous, no overlap', function () {
    $plan = e0Plan('price');

    try {
        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = e0Run(['sanad:plan-price-probe', (string) $plan->id, (string) (11 + $i)]);
        }

        $outcomes = [];
        foreach ($processes as $p) {
            $p->wait();
            expect($p->getExitCode())->toBe(0, $p->getOutput().$p->getErrorOutput());
            $outcomes[] = trim($p->getOutput());
        }

        $versions = PlanPriceVersion::query()->where('plan_id', $plan->id)->orderBy('effective_from')->orderBy('id')->get();
        $versioned = count(array_filter($outcomes, fn ($o) => $o === 'versioned'));

        expect($versioned)->toBeGreaterThanOrEqual(1)
            ->and($versions)->toHaveCount($versioned)
            ->and($versions->whereNull('effective_until'))->toHaveCount(1)
            ->and($versions->last()->effective_until)->toBeNull();

        for ($i = 1; $i < $versions->count(); $i++) {
            expect($versions[$i - 1]->effective_until)->not->toBeNull()
                ->and($versions[$i - 1]->effective_until->equalTo($versions[$i]->effective_from))->toBeTrue() // contiguous
                ->and($versions[$i]->effective_from->greaterThan($versions[$i - 1]->effective_from))->toBeTrue(); // strictly ordered, no overlap
        }

        // The open version carries the plan's final price.
        expect((string) $versions->last()->price)->toBe((string) $plan->fresh()->price);
    } finally {
        e0Cleanup([$plan], []);
    }
});

it('concurrent baseline runs leave one baseline per subscription, one open version per plan and one audit entry', function () {
    $plan = e0Plan('baseline');
    $users = [];
    for ($i = 0; $i < 3; $i++) {
        $user = User::factory()->create(['is_admin' => false]);
        Subscription::create(['subscriber_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active', 'started_at' => now()]);
        $users[] = $user;
    }
    AuditLog::where('action', AuditActions::FinanceHistoryBaselineApplied)->delete();
    $subscriptionIds = Subscription::query()->whereIn('subscriber_id', collect($users)->pluck('id'))->pluck('id');

    try {
        $processes = [];
        for ($i = 0; $i < 5; $i++) {
            $processes[] = e0Run(['sanad:finance:history-baseline', '--apply']);
        }
        foreach ($processes as $p) {
            $p->wait();
            expect($p->getExitCode())->toBe(0, $p->getOutput().$p->getErrorOutput());
        }

        expect(SubscriptionEvent::query()->whereIn('subscription_id', $subscriptionIds)->where('event_type', 'baseline')->count())->toBe(3)
            ->and(SubscriptionEvent::query()->whereIn('subscription_id', $subscriptionIds)->count())->toBe(3)
            ->and(PlanPriceVersion::query()->where('plan_id', $plan->id)->count())->toBe(1)
            ->and(PlanPriceVersion::query()->where('plan_id', $plan->id)->whereNull('effective_until')->count())->toBe(1)
            ->and(AuditLog::where('action', AuditActions::FinanceHistoryBaselineApplied)->count())->toBe(1);
    } finally {
        e0Cleanup([$plan], $users);
    }
});

it('serialises concurrent subscription transitions and keeps the event chain consistent', function () {
    $plan = e0Plan('transition');
    $user = User::factory()->create(['is_admin' => false]);
    $subscription = Subscription::create(['subscriber_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active', 'started_at' => now(), 'current_period_end' => now()->addMonth()]);

    try {
        $processes = [];
        foreach (['suspend', 'activate', 'suspend', 'extend', 'activate', 'suspend'] as $action) {
            $processes[] = e0Run(['sanad:subscription-transition-probe', (string) $subscription->id, $action]);
        }
        foreach ($processes as $p) {
            $p->wait();
            expect($p->getExitCode())->toBe(0, $p->getOutput().$p->getErrorOutput());
        }

        $events = SubscriptionEvent::query()->where('subscription_id', $subscription->id)->orderBy('id')->get();

        expect($events)->toHaveCount(6)
            ->and($events->first()->from_status->value)->toBe('active');

        for ($i = 1; $i < $events->count(); $i++) {
            expect($events[$i]->from_status)->toBe($events[$i - 1]->to_status);
        }

        expect($events->last()->to_status)->toBe($subscription->fresh()->status)
            ->and(AuditLog::where('subject_type', (new Subscription)->getMorphClass())->where('subject_id', $subscription->id)->count())->toBe(6);
    } finally {
        e0Cleanup([$plan], [$user]);
    }
});
