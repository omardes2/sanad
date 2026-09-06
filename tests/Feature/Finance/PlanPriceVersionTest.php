<?php

declare(strict_types=1);

use App\Enums\BillingPeriod;
use App\Enums\PlanPriceVersionSource;
use App\Exceptions\Billing\PlanPriceOverlapException;
use App\Livewire\Dashboard\Plans;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\PlanPriceVersion;
use App\Services\Billing\PlanPriceBook;
use App\Support\Audit\AuditActions;
use App\Support\Rbac\Role;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Phase E0 — plan price versions: [effective_from, effective_until) periods,
 * one open version per plan, closed at the next change, no overlap, no
 * back-dating, admin-only financial changes create them.
 */
function planFormE0(array $overrides = []): array
{
    return array_merge([
        'name' => 'Plus', 'slug' => 'plus', 'description' => '', 'price' => '10', 'currency' => 'USD',
        'billing_period' => 'monthly', 'trial_days' => 0, 'is_active' => true, 'is_default' => false, 'sort_order' => 1,
    ], $overrides);
}

it('opens the first version when a plan is created from the Plans page and closes it on a financial change', function () {
    $admin = userWithRole(Role::SuperAdmin);

    Livewire::actingAs($admin)->test(Plans::class)->call('new')->set(planFormE0())->call('save')->assertHasNoErrors();
    $plan = Plan::where('slug', 'plus')->sole();
    $v1 = PlanPriceVersion::query()->sole();

    expect($v1->plan_id)->toBe($plan->id)
        ->and((string) $v1->price)->toBe('10.00')
        ->and($v1->currency)->toBe('USD')
        ->and($v1->billing_period)->toBe(BillingPeriod::Monthly)
        ->and($v1->effective_until)->toBeNull()
        ->and($v1->source)->toBe(PlanPriceVersionSource::Admin)
        ->and($v1->created_by)->toBe($admin->id)
        ->and($v1->effective_from->diffInSeconds(CarbonImmutable::now(), true))->toBeLessThan(5)
        ->and(AuditLog::where('action', AuditActions::PlanPriceVersioned)->count())->toBe(1);

    $this->travel(60)->seconds();

    Livewire::actingAs($admin)->test(Plans::class)->call('edit', $plan->id)->set('price', '12.5')->set('billing_period', 'yearly')->call('save')->assertHasNoErrors();

    $versions = PlanPriceVersion::query()->orderBy('id')->get();

    expect($versions)->toHaveCount(2)
        ->and($versions[0]->effective_until)->not->toBeNull()
        ->and($versions[0]->effective_until->equalTo($versions[1]->effective_from))->toBeTrue() // contiguous, no gap, no overlap
        ->and((string) $versions[0]->price)->toBe('10.00') // history untouched
        ->and((string) $versions[1]->price)->toBe('12.50')
        ->and($versions[1]->billing_period)->toBe(BillingPeriod::Yearly)
        ->and($versions[1]->effective_until)->toBeNull()
        ->and(PlanPriceVersion::query()->where('plan_id', $plan->id)->whereNull('effective_until')->count())->toBe(1);
});

it('creates no version when only non-financial fields change', function () {
    $admin = userWithRole(Role::SuperAdmin);
    Livewire::actingAs($admin)->test(Plans::class)->call('new')->set(planFormE0())->call('save');
    $plan = Plan::where('slug', 'plus')->sole();

    Livewire::actingAs($admin)->test(Plans::class)->call('edit', $plan->id)->set('name', 'Plus renamed')->set('description', 'x')->set('trial_days', 3)->call('save')->assertHasNoErrors();

    expect(PlanPriceVersion::query()->count())->toBe(1)
        ->and($plan->fresh()->name)->toBe('Plus renamed');
});

it('resolves the version in force at an instant and returns null before the first version', function () {
    $plan = billingPlan(attrs: ['price' => '10.00', 'currency' => 'USD', 'billing_period' => 'monthly']);
    $book = app(PlanPriceBook::class);
    $t1 = CarbonImmutable::parse('2026-09-10 10:00:00', 'UTC');
    $t2 = CarbonImmutable::parse('2026-09-12 10:00:00', 'UTC');

    $book->recordVersion($plan, $t1, PlanPriceVersionSource::Baseline);
    $plan->forceFill(['price' => '20.00'])->save();
    $book->recordVersion($plan, $t2, PlanPriceVersionSource::Admin);

    expect($book->versionFor($plan->id, $t1->subSecond()))->toBeNull() // before the history: nothing is invented
        ->and((string) $book->versionFor($plan->id, $t1)->price)->toBe('10.00')
        ->and((string) $book->versionFor($plan->id, $t2->subSecond())->price)->toBe('10.00')
        ->and((string) $book->versionFor($plan->id, $t2)->price)->toBe('20.00')
        ->and((string) $book->versionFor($plan->id, $t2->addYear())->price)->toBe('20.00')
        ->and($book->openVersionFor($plan->id)->effective_from->equalTo($t2))->toBeTrue();
});

it('refuses a version that starts at or before the open version start (no back-dating, no overlap)', function () {
    $plan = billingPlan(attrs: ['price' => '10.00']);
    $book = app(PlanPriceBook::class);
    $t = CarbonImmutable::parse('2026-09-10 10:00:00', 'UTC');
    $book->recordVersion($plan, $t, PlanPriceVersionSource::Baseline);

    expect(fn () => $book->recordVersion($plan, $t, PlanPriceVersionSource::Admin))->toThrow(PlanPriceOverlapException::class)
        ->and(fn () => $book->recordVersion($plan, $t->subDay(), PlanPriceVersionSource::Admin))->toThrow(PlanPriceOverlapException::class)
        ->and(PlanPriceVersion::query()->count())->toBe(1)
        ->and(PlanPriceVersion::query()->first()->effective_until)->toBeNull();

    // A closed period in the past cannot be re-entered either.
    $book->recordVersion($plan, $t->addDay(), PlanPriceVersionSource::Admin);
    expect(fn () => $book->recordVersion($plan, $t->addHours(12), PlanPriceVersionSource::Admin))->toThrow(PlanPriceOverlapException::class)
        ->and(PlanPriceVersion::query()->count())->toBe(2);
});

it('is atomic with the plan save: a version failure rolls the financial change back', function () {
    $admin = userWithRole(Role::SuperAdmin);
    $plan = billingPlan(attrs: ['slug' => 'plus', 'price' => '10.00', 'currency' => 'USD', 'billing_period' => 'monthly']);
    // Open version already starting in the future relative to "now" → the next admin version overlaps.
    app(PlanPriceBook::class)->recordVersion($plan, CarbonImmutable::now()->addDay(), PlanPriceVersionSource::Admin);

    try {
        Livewire::actingAs($admin)->test(Plans::class)->call('edit', $plan->id)->set('price', '99')->call('save');
    } catch (PlanPriceOverlapException) {
        // expected
    }

    expect((string) $plan->fresh()->price)->toBe('10.00')
        ->and(PlanPriceVersion::query()->count())->toBe(1)
        ->and(AuditLog::where('action', AuditActions::PlanFinancialsUpdated)->count())->toBe(0);
});

it('blocks deleting a plan that has price history', function () {
    $plan = billingPlan(attrs: ['price' => '10.00']);
    app(PlanPriceBook::class)->recordVersion($plan, CarbonImmutable::now(), PlanPriceVersionSource::Baseline);

    // A savepoint keeps the outer test transaction usable on PostgreSQL (25P02).
    expect(fn () => DB::transaction(fn () => $plan->delete()))->toThrow(QueryException::class)
        ->and(Plan::query()->whereKey($plan->id)->exists())->toBeTrue();
});
