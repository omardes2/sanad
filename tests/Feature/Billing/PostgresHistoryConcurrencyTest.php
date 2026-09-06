<?php

declare(strict_types=1);

use App\Enums\PlanPriceVersionSource;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\PlanPriceVersion;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\User;
use App\Services\Billing\PlanPriceBook;
use App\Support\Audit\AuditActions;
use App\Support\Billing\SubscriptionStateToken;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * GENUINE parallel tests for the Phase E0 financial history on PostgreSQL:
 *  - N admins editing ONE plan's price from the SAME open version → exactly one
 *    wins (one close, one new version, one audit), the rest are stale, no
 *    overlap; the losers can retry from the new version;
 *  - concurrent baseline runs → one baseline per subscription, one open
 *    version per plan, one audit entry;
 *  - N admins acting on the SAME viewed subscription state → exactly one
 *    wins (one event, one audit), the rest are stale, projection = winner.
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

it('of 6 concurrent financial edits from the same open version exactly one wins; the rest are stale; no overlap', function () {
    $plan = e0Plan('price');
    $v1 = app(PlanPriceBook::class)->recordVersion($plan, now()->subMinute()->toImmutable(), PlanPriceVersionSource::Baseline);

    try {
        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = e0Run(['sanad:plan-price-probe', (string) $plan->id, (string) (11 + $i), (string) $v1->id]);
        }

        $outcomes = [];
        foreach ($processes as $p) {
            $p->wait();
            expect($p->getExitCode())->toBe(0, $p->getOutput().$p->getErrorOutput());
            $outcomes[] = trim($p->getOutput());
        }

        $versions = PlanPriceVersion::query()->where('plan_id', $plan->id)->orderBy('effective_from')->orderBy('id')->get();
        $counts = array_count_values($outcomes);

        expect($counts['versioned'] ?? 0)->toBe(1)
            ->and($counts['stale'] ?? 0)->toBe(5)
            ->and($versions)->toHaveCount(2) // v1 (closed once) + the winner's version
            ->and($versions[0]->id)->toBe($v1->id)
            ->and($versions[0]->effective_until)->not->toBeNull()
            ->and($versions[0]->effective_until->equalTo($versions[1]->effective_from))->toBeTrue() // contiguous, no overlap
            ->and($versions[1]->effective_until)->toBeNull()
            ->and($versions->whereNull('effective_until'))->toHaveCount(1)
            ->and((string) $versions[1]->price)->toBe((string) $plan->fresh()->price) // projection = winner
            ->and(AuditLog::where('subject_type', (new PlanPriceVersion)->getMorphClass())->where('subject_id', $versions[1]->id)->count())->toBe(1);

        // A loser retries LATER from the NEW open version and succeeds (versions
        // are second-precise: a retry in the same second as the winner's start is
        // refused as an overlap, never written).
        usleep(1_100_000);
        $retry = e0Run(['sanad:plan-price-probe', (string) $plan->id, '99', (string) $versions[1]->id]);
        $retry->wait();
        expect(trim($retry->getOutput()))->toBe('versioned')
            ->and(PlanPriceVersion::query()->where('plan_id', $plan->id)->count())->toBe(3);
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

it('of 6 concurrent admin transitions from the same viewed state exactly one wins; the rest are stale; projection = winner', function () {
    $plan = e0Plan('transition');
    $user = User::factory()->create(['is_admin' => false]);
    $subscription = Subscription::create(['subscriber_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active', 'started_at' => now(), 'current_period_start' => now(), 'current_period_end' => now()->addMonth()]);
    $token = SubscriptionStateToken::for($subscription); // what every admin saw

    try {
        $processes = [];
        foreach (['suspend', 'extend', 'suspend', 'extend', 'suspend', 'extend'] as $action) {
            $processes[] = e0Run(['sanad:subscription-transition-probe', (string) $subscription->id, $action, $token]);
        }

        $outcomes = [];
        foreach ($processes as $p) {
            $p->wait();
            expect($p->getExitCode())->toBe(0, $p->getOutput().$p->getErrorOutput());
            $outcomes[] = trim($p->getOutput());
        }

        $winners = array_values(array_filter($outcomes, fn ($o) => str_starts_with($o, 'ok:')));
        $events = SubscriptionEvent::query()->where('subscription_id', $subscription->id)->get();

        expect($winners)->toHaveCount(1)
            ->and(count(array_filter($outcomes, fn ($o) => $o === 'stale')))->toBe(5)
            ->and($events)->toHaveCount(1)
            ->and($events->first()->from_status->value)->toBe('active')
            ->and($events->first()->to_status)->toBe($subscription->fresh()->status) // projection = winner
            ->and('ok:'.$subscription->fresh()->status->value)->toBe($winners[0])
            ->and(AuditLog::where('subject_type', (new Subscription)->getMorphClass())->where('subject_id', $subscription->id)->count())->toBe(1);

        // A loser that refreshes gets the new token and can act.
        $retry = e0Run(['sanad:subscription-transition-probe', (string) $subscription->id, 'extend', SubscriptionStateToken::for($subscription->fresh())]);
        $retry->wait();
        expect(trim($retry->getOutput()))->toStartWith('ok:')
            ->and(SubscriptionEvent::query()->where('subscription_id', $subscription->id)->count())->toBe(2);
    } finally {
        e0Cleanup([$plan], [$user]);
    }
});
