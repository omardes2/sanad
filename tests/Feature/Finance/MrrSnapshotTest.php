<?php

declare(strict_types=1);

use App\Enums\SubscriptionStatus;
use App\Models\AuditLog;
use App\Models\FinanceMrrSnapshot;
use App\Models\Subscription;
use App\Services\Finance\MrrCalculator;
use App\Support\Audit\AuditActions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Console\Exception\InvalidOptionException;

uses(RefreshDatabase::class);

/**
 * Phase D1 — Current Calculated MRR (as of now) and the daily snapshot command:
 * per currency, never summed across currencies, past_due/trialing never MRR,
 * today only, idempotent, never rewritten.
 */
function mrrFixture(): array
{
    $usdMonthly = billingPlan(attrs: ['slug' => 'usd-monthly', 'price' => '10.00', 'currency' => 'USD', 'billing_period' => 'monthly']);
    $usdYearly = billingPlan(attrs: ['slug' => 'usd-yearly', 'price' => '100.00', 'currency' => 'USD', 'billing_period' => 'yearly']);
    $ilsMonthly = billingPlan(attrs: ['slug' => 'ils-monthly', 'price' => '35.00', 'currency' => 'ILS', 'billing_period' => 'monthly']);

    billingSubscriber($usdMonthly);
    billingSubscriber($usdMonthly);
    billingSubscriber($usdMonthly, ['status' => SubscriptionStatus::PastDue]);
    billingSubscriber($usdMonthly, ['status' => SubscriptionStatus::Trialing, 'trial_ends_at' => now()->addDays(3)]);
    billingSubscriber($usdMonthly, ['status' => SubscriptionStatus::Cancelled]);
    billingSubscriber($usdMonthly, ['current_period_end' => now()->subDay()]); // active but lapsed: not MRR
    billingSubscriber($usdYearly);
    billingSubscriber($ilsMonthly);
    billingSubscriber($ilsMonthly, ['current_period_end' => null]); // open-ended active
    billingSubscriber(null);
    Subscription::create(['subscriber_id' => billingSubscriber(null)->id, 'plan_id' => null, 'status' => SubscriptionStatus::Active, 'started_at' => now()]);

    return [$usdMonthly, $usdYearly, $ilsMonthly];
}

it('computes current MRR / ARR / ARPU per currency from active, unlapsed subscriptions only', function () {
    [$usdMonthly, $usdYearly, $ilsMonthly] = mrrFixture();

    $set = app(MrrCalculator::class)->current();
    $by = $set->byCurrency();

    expect($set->calculationVersion)->toBe(MrrCalculator::VERSION)
        ->and(array_keys($by))->toBe(['ILS', 'USD', 'XXX'])
        ->and($by['USD']['mrr'])->toBe('28.333333') // 2 × 10 + 100/12
        ->and($by['USD']['arr'])->toBe('339.999996')
        ->and($by['USD']['arpu'])->toBe('9.444444')
        ->and($by['USD']['active'])->toBe(3)
        ->and($by['USD']['trialing'])->toBe(1)
        ->and($by['USD']['past_due'])->toBe(1)
        ->and($by['ILS']['mrr'])->toBe('70.000000')
        ->and($by['ILS']['active'])->toBe(2)
        ->and($by['XXX']['mrr'])->toBe('0.000000')
        ->and($by['XXX']['active'])->toBe(1)
        ->and($by['XXX']['arpu'])->toBe('0.000000');

    $rows = collect($set->rows)->keyBy('planKey');

    expect($rows[(string) $usdMonthly->id]->mrrNormalized)->toBe('20.000000')
        ->and($rows[(string) $usdMonthly->id]->activeCount)->toBe(2)
        ->and($rows[(string) $usdMonthly->id]->pastDueCount)->toBe(1)
        ->and($rows[(string) $usdYearly->id]->mrrNormalized)->toBe('8.333333')
        ->and($rows[(string) $usdYearly->id]->billingPeriod)->toBe('yearly')
        ->and($rows[(string) $ilsMonthly->id]->currency)->toBe('ILS')
        ->and($rows['none']->currency)->toBe(FinanceMrrSnapshot::NO_CURRENCY)
        ->and($rows['none']->planPrice)->toBeNull();
});

it('captures today\'s (UTC) snapshot rows atomically with an audit entry and is a no-op on re-run', function () {
    [$usdMonthly] = mrrFixture();
    $today = CarbonImmutable::now('UTC')->toDateString();

    $this->artisan('sanad:finance:snapshot')
        ->expectsOutputToContain('NOT collected revenue')
        ->expectsOutputToContain("Captured 4 snapshot row(s) for {$today} (UTC).")
        ->assertSuccessful();

    $rows = FinanceMrrSnapshot::query()->where('snapshot_date', $today)->get();
    $usd = $rows->firstWhere('plan_key', (string) $usdMonthly->id);

    expect($rows)->toHaveCount(4)
        ->and($usd->currency)->toBe('USD')
        ->and($usd->plan_slug)->toBe('usd-monthly')
        ->and((string) $usd->plan_price)->toBe('10.00')
        ->and($usd->billing_period)->toBe('monthly')
        ->and($usd->active_count)->toBe(2)
        ->and($usd->trialing_count)->toBe(1)
        ->and($usd->past_due_count)->toBe(1)
        ->and((string) $usd->mrr_normalized)->toBe('20.000000')
        ->and($usd->calculation_version)->toBe(MrrCalculator::VERSION)
        ->and($usd->captured_at)->not->toBeNull()
        ->and(AuditLog::where('action', AuditActions::FinanceMrrSnapshotCaptured)->count())->toBe(1)
        ->and(AuditLog::where('action', AuditActions::FinanceMrrSnapshotCaptured)->first()->actor)->toBe('console');

    // The world changes after the capture: price up, one more subscriber.
    $usdMonthly->forceFill(['price' => '99.00'])->save();
    billingSubscriber($usdMonthly);
    $capturedAt = $usd->captured_at;

    $this->artisan('sanad:finance:snapshot')
        ->expectsOutputToContain('already captured (4 row(s)) — nothing written')
        ->assertSuccessful();

    $again = FinanceMrrSnapshot::query()->where('snapshot_date', $today)->where('plan_key', (string) $usdMonthly->id)->first();

    expect(FinanceMrrSnapshot::query()->count())->toBe(4)
        ->and((string) $again->plan_price)->toBe('10.00') // the historical row is NOT rewritten
        ->and($again->active_count)->toBe(2)
        ->and($again->captured_at->equalTo($capturedAt))->toBeTrue()
        ->and(AuditLog::where('action', AuditActions::FinanceMrrSnapshotCaptured)->count())->toBe(1);
});

it('has no date option: a snapshot can only be captured for the current UTC day', function () {
    expect(fn () => $this->artisan('sanad:finance:snapshot', ['--date' => '2026-01-01']))
        ->toThrow(InvalidOptionException::class);

    expect(FinanceMrrSnapshot::query()->count())->toBe(0);
});

it('dry-run renders the picture and writes nothing', function () {
    mrrFixture();

    $this->artisan('sanad:finance:snapshot', ['--dry-run' => true])
        ->expectsOutputToContain('Dry run — nothing written.')
        ->assertSuccessful();

    expect(FinanceMrrSnapshot::query()->count())->toBe(0)
        ->and(AuditLog::where('action', AuditActions::FinanceMrrSnapshotCaptured)->count())->toBe(0);
});

it('marks a day with no subscriptions as captured with a single zero row instead of leaving it open', function () {
    $today = CarbonImmutable::now('UTC')->toDateString();

    $this->artisan('sanad:finance:snapshot')->assertSuccessful();

    $row = FinanceMrrSnapshot::query()->where('snapshot_date', $today)->sole();

    expect($row->plan_key)->toBe(FinanceMrrSnapshot::PLAN_KEY_NONE)
        ->and($row->currency)->toBe(FinanceMrrSnapshot::NO_CURRENCY)
        ->and($row->active_count)->toBe(0)
        ->and((string) $row->mrr_normalized)->toBe('0.000000');

    billingSubscriber(billingPlan(attrs: ['price' => '10.00', 'currency' => 'USD']));

    $this->artisan('sanad:finance:snapshot')->expectsOutputToContain('nothing written')->assertSuccessful();

    expect(FinanceMrrSnapshot::query()->where('snapshot_date', $today)->count())->toBe(1);
});

it('keeps the snapshot row when its plan is deleted (historical reference, no FK)', function () {
    $plan = billingPlan(attrs: ['price' => '10.00', 'currency' => 'USD']);
    billingSubscriber($plan);

    $this->artisan('sanad:finance:snapshot')->assertSuccessful();
    $plan->delete();

    expect(FinanceMrrSnapshot::query()->where('plan_key', (string) $plan->id)->exists())->toBeTrue();
});
