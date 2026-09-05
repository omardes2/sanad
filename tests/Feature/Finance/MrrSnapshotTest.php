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
        ->and(array_keys($by))->toBe(['ILS', 'USD']) // XXX (no plan) is a marker, never a currency group
        ->and($by['USD']['mrr'])->toBe('28.333333') // 2 × 10 + 100/12
        ->and($by['USD']['arr'])->toBe('339.999996')
        ->and($by['USD']['arpu'])->toBe('9.444444')
        ->and($by['USD']['active'])->toBe(3)
        ->and($by['USD']['trialing'])->toBe(1)
        ->and($by['USD']['past_due'])->toBe(1)
        ->and($by['ILS']['mrr'])->toBe('70.000000')
        ->and($by['ILS']['active'])->toBe(2)
        ->and($set->unassigned())->toBe(['active' => 1, 'trialing' => 0, 'past_due' => 0]);

    $rows = collect($set->rows)->keyBy('planKey');

    expect($rows["plan:{$usdMonthly->id}"]->mrrNormalized)->toBe('20.000000')
        ->and($rows["plan:{$usdMonthly->id}"]->activeCount)->toBe(2)
        ->and($rows["plan:{$usdMonthly->id}"]->pastDueCount)->toBe(1)
        ->and($rows["plan:{$usdYearly->id}"]->mrrNormalized)->toBe('8.333333')
        ->and($rows["plan:{$usdYearly->id}"]->billingPeriod)->toBe('yearly')
        ->and($rows["plan:{$ilsMonthly->id}"]->currency)->toBe('ILS')
        ->and($rows['none']->currency)->toBe(FinanceMrrSnapshot::NO_CURRENCY)
        ->and($rows['none']->planPrice)->toBeNull()
        ->and($rows['none']->mrrNormalized)->toBe('0.000000');
});

it('plan_key is a stable plan identity (plan:<id>) that a slug, price or period change can never alter', function () {
    $plan = billingPlan(attrs: ['slug' => 'starter', 'price' => '10.00', 'currency' => 'USD', 'billing_period' => 'monthly']);
    billingSubscriber($plan);
    $calculator = app(MrrCalculator::class);

    $before = collect($calculator->current()->rows)->firstWhere('planId', $plan->id);

    $this->artisan('sanad:finance:snapshot')->assertSuccessful();
    $stored = FinanceMrrSnapshot::query()->where('plan_id', $plan->id)->sole();

    $plan->forceFill(['slug' => 'starter-renamed', 'price' => '99.00', 'billing_period' => 'yearly'])->save();
    $after = collect($calculator->current()->rows)->firstWhere('planId', $plan->id);

    expect($before->planKey)->toBe("plan:{$plan->id}")
        ->and($after->planKey)->toBe($before->planKey) // same plan, same identity
        ->and($after->planSlug)->toBe('starter-renamed') // descriptive history moves with the plan
        ->and($stored->plan_key)->toBe("plan:{$plan->id}")
        ->and($stored->plan_slug)->toBe('starter') // the captured description never changes
        ->and((string) $stored->plan_price)->toBe('10.00')
        ->and($stored->plan_key)->not->toContain('starter')
        ->and(FinanceMrrSnapshot::planKeyFor(null))->toBe(FinanceMrrSnapshot::PLAN_KEY_NONE)
        ->and(FinanceMrrSnapshot::planKeyFor(7))->toBe('plan:7');
});

it('the XXX/none marker never enters MRR, ARR or ARPU, is not a currency group and is not a plan', function () {
    $usd = billingPlan(attrs: ['price' => '10.00', 'currency' => 'USD', 'billing_period' => 'monthly']);
    billingSubscriber($usd);
    billingSubscriber($usd);
    // Two subscribers WITHOUT a plan (active + past_due) — counted, never revenue.
    Subscription::create(['subscriber_id' => billingSubscriber(null)->id, 'plan_id' => null, 'status' => SubscriptionStatus::Active, 'started_at' => now()]);
    Subscription::create(['subscriber_id' => billingSubscriber(null)->id, 'plan_id' => null, 'status' => SubscriptionStatus::PastDue, 'started_at' => now()]);

    $set = app(MrrCalculator::class)->current();
    $by = $set->byCurrency();
    $marker = collect($set->rows)->firstWhere('planKey', FinanceMrrSnapshot::PLAN_KEY_NONE);

    expect(array_keys($by))->toBe(['USD'])
        ->and($by['USD']['mrr'])->toBe('20.000000')
        ->and($by['USD']['arr'])->toBe('240.000000')
        ->and($by['USD']['arpu'])->toBe('10.000000') // 20 / 2 — the no-plan active subscriber is not in the denominator
        ->and($by['USD']['active'])->toBe(2)
        ->and($by['USD']['past_due'])->toBe(0)
        ->and($marker->currency)->toBe('XXX')
        ->and($marker->planId)->toBeNull()
        ->and($marker->planSlug)->toBeNull()
        ->and($marker->planPrice)->toBeNull()
        ->and($marker->mrrNormalized)->toBe('0.000000')
        ->and($set->unassigned())->toBe(['active' => 1, 'trialing' => 0, 'past_due' => 1]);

    $this->artisan('sanad:finance:snapshot')
        ->expectsOutputToContain('No plan (marker, not a currency, never revenue): active 1 · trialing 0 · past_due 1')
        ->assertSuccessful();

    $row = FinanceMrrSnapshot::query()->where('plan_key', FinanceMrrSnapshot::PLAN_KEY_NONE)->sole();

    expect($row->isMarker())->toBeTrue()
        ->and($row->plan_id)->toBeNull()
        ->and((string) $row->mrr_normalized)->toBe('0.000000')
        ->and(FinanceMrrSnapshot::query()->where('plan_key', "plan:{$usd->id}")->sole()->isMarker())->toBeFalse();
});

it('captures today\'s (UTC) snapshot rows atomically with an audit entry and is a no-op on re-run', function () {
    [$usdMonthly] = mrrFixture();
    $today = CarbonImmutable::now('UTC')->toDateString();

    $this->artisan('sanad:finance:snapshot')
        ->expectsOutputToContain('NOT collected revenue')
        ->expectsOutputToContain("Captured 4 snapshot row(s) for {$today} (UTC).")
        ->assertSuccessful();

    $rows = FinanceMrrSnapshot::query()->where('snapshot_date', $today)->get();
    $usd = $rows->firstWhere('plan_key', "plan:{$usdMonthly->id}");

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

    $again = FinanceMrrSnapshot::query()->where('snapshot_date', $today)->where('plan_key', "plan:{$usdMonthly->id}")->first();

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

    expect(FinanceMrrSnapshot::query()->where('plan_key', "plan:{$plan->id}")->exists())->toBeTrue();
});
