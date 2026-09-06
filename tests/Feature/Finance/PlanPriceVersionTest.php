<?php

declare(strict_types=1);

use App\Enums\BillingPeriod;
use App\Enums\PlanPriceVersionSource;
use App\Exceptions\Billing\PlanPriceOverlapException;
use App\Exceptions\Billing\StalePlanPriceVersionException;
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
        ->and(AuditLog::where('action', AuditActions::PlanFinancialsUpdated)->count())->toBe(0)
        ->and(AuditLog::where('action', AuditActions::PlanPriceVersioned)->count())->toBe(1); // only the pre-existing version's audit row
});

it('blocks deleting a plan that has price history', function () {
    $plan = billingPlan(attrs: ['price' => '10.00']);
    app(PlanPriceBook::class)->recordVersion($plan, CarbonImmutable::now(), PlanPriceVersionSource::Baseline);

    // A savepoint keeps the outer test transaction usable on PostgreSQL (25P02).
    expect(fn () => DB::transaction(fn () => $plan->delete()))->toThrow(QueryException::class)
        ->and(Plan::query()->whereKey($plan->id)->exists())->toBeTrue();
});

it('refuses a financial edit whose open version is no longer the one the form was loaded from: nothing written', function () {
    $admin = userWithRole(Role::SuperAdmin);
    Livewire::actingAs($admin)->test(Plans::class)->call('new')->set(planFormE0())->call('save');
    $plan = Plan::where('slug', 'plus')->sole();
    $v1 = PlanPriceVersion::query()->sole();

    // Two admins open the same form (same open version v1).
    $adminA = Livewire::actingAs($admin)->test(Plans::class)->call('edit', $plan->id);
    $adminB = Livewire::actingAs($admin)->test(Plans::class)->call('edit', $plan->id);
    expect($adminA->get('expectedPriceVersionId'))->toBe($v1->id)->and($adminB->get('expectedPriceVersionId'))->toBe($v1->id);

    $this->travel(30)->seconds();
    $adminA->set('price', '20')->call('save')->assertHasNoErrors();
    $v2 = PlanPriceVersion::query()->whereNull('effective_until')->sole();

    $this->travel(30)->seconds();
    $adminB->set('price', '30')->call('save')->assertHasErrors(['price']);

    expect((string) $plan->fresh()->price)->toBe('20.00') // B wrote nothing
        ->and(PlanPriceVersion::query()->count())->toBe(2)
        ->and($v2->fresh()->effective_until)->toBeNull() // v2 not closed
        ->and($v1->fresh()->effective_until->equalTo($v2->effective_from))->toBeTrue() // v1 closed exactly once
        ->and(AuditLog::where('action', AuditActions::PlanPriceVersioned)->count())->toBe(2)
        ->and(AuditLog::where('action', AuditActions::PlanFinancialsUpdated)->count())->toBe(1)
        ->and($adminB->get('expectedPriceVersionId'))->toBe($v2->id); // refreshed: B may retry from the new version

    $this->travel(30)->seconds();
    $adminB->set('price', '30')->call('save')->assertHasNoErrors();
    expect((string) $plan->fresh()->price)->toBe('30.00')->and(PlanPriceVersion::query()->count())->toBe(3);

    // Admin A's form is stale again (it still carries price 20 while the plan is at 30):
    // saving it would silently revert the price, so it is refused — nothing written.
    $adminA->set('name', 'Renamed')->call('save')->assertHasErrors(['price']);
    expect($plan->fresh()->name)->not->toBe('Renamed')->and((string) $plan->fresh()->price)->toBe('30.00')->and(PlanPriceVersion::query()->count())->toBe(3);

    // A non-financial edit from a FRESH form saves without touching the versions.
    Livewire::actingAs($admin)->test(Plans::class)->call('edit', $plan->id)->set('name', 'Renamed')->call('save')->assertHasNoErrors();
    expect($plan->fresh()->name)->toBe('Renamed')->and(PlanPriceVersion::query()->count())->toBe(3);
});

it('enforces the expected open version at the service level too', function () {
    $plan = billingPlan(attrs: ['price' => '10.00']);
    $book = app(PlanPriceBook::class);
    $v1 = $book->recordVersion($plan, CarbonImmutable::now()->subDay(), PlanPriceVersionSource::Baseline);

    expect(fn () => $book->recordVersion($plan, CarbonImmutable::now(), PlanPriceVersionSource::Admin, null, null, true))->toThrow(StalePlanPriceVersionException::class)
        ->and(fn () => $book->recordVersion($plan, CarbonImmutable::now(), PlanPriceVersionSource::Admin, null, $v1->id + 999, true))->toThrow(StalePlanPriceVersionException::class)
        ->and(PlanPriceVersion::query()->count())->toBe(1)
        ->and($v1->fresh()->effective_until)->toBeNull();

    $book->recordVersion($plan, CarbonImmutable::now(), PlanPriceVersionSource::Admin, null, $v1->id, true);
    expect(PlanPriceVersion::query()->count())->toBe(2);
});

it('lets the stale admin retry IMMEDIATELY after a refresh (no sleep): three contiguous, strictly increasing periods', function () {
    $admin = userWithRole(Role::SuperAdmin);
    Livewire::actingAs($admin)->test(Plans::class)->call('new')->set(planFormE0())->call('save')->assertHasNoErrors();
    $plan = Plan::where('slug', 'plus')->sole();
    $v1 = PlanPriceVersion::query()->sole();

    // 1–2. Both admins open the same form; A saves; B (old preview) is refused as stale.
    $adminA = Livewire::actingAs($admin)->test(Plans::class)->call('edit', $plan->id);
    $adminB = Livewire::actingAs($admin)->test(Plans::class)->call('edit', $plan->id);
    $adminA->set('price', '20')->call('save')->assertHasNoErrors();
    $adminB->set('price', '30')->call('save')->assertHasErrors(['price']);

    // 3. B refreshes the form and gets the new expected version.
    $v2 = PlanPriceVersion::query()->whereNull('effective_until')->sole();
    $adminB->call('edit', $plan->id);
    expect($adminB->get('expectedPriceVersionId'))->toBe($v2->id);

    // 4–5. B retries at once — no sleep, no travel — and succeeds.
    $adminB->set('price', '30')->call('save')->assertHasNoErrors();

    // 6. Three periods: ordered, contiguous, no overlap, no zero-length interval.
    $versions = PlanPriceVersion::query()->where('plan_id', $plan->id)->orderBy('effective_from')->orderBy('id')->get();

    expect($versions->pluck('id')->all())->toBe([$v1->id, $v2->id, $versions[2]->id])
        ->and((string) $plan->fresh()->price)->toBe('30.00')
        ->and($versions->map(fn ($v) => (string) $v->price)->all())->toBe(['10.00', '20.00', '30.00'])
        ->and($versions[0]->effective_until->equalTo($versions[1]->effective_from))->toBeTrue()
        ->and($versions[1]->effective_until->equalTo($versions[2]->effective_from))->toBeTrue()
        ->and($versions[2]->effective_until)->toBeNull()
        ->and($versions[0]->effective_from->lessThan($versions[1]->effective_from))->toBeTrue()
        ->and($versions[1]->effective_from->lessThan($versions[2]->effective_from))->toBeTrue()
        ->and($versions[0]->effective_until->greaterThan($versions[0]->effective_from))->toBeTrue()
        ->and($versions[1]->effective_until->greaterThan($versions[1]->effective_from))->toBeTrue()
        ->and($versions[1]->effective_from->format('u'))->not->toBe('000000') // microseconds really stored
        ->and(app(PlanPriceBook::class)->versionFor($plan->id, CarbonImmutable::instance($versions[1]->effective_from))->id)->toBe($v2->id)
        ->and(app(PlanPriceBook::class)->versionFor($plan->id, CarbonImmutable::instance($versions[1]->effective_from)->subMicrosecond())->id)->toBe($v1->id)
        ->and(AuditLog::where('action', AuditActions::PlanPriceVersioned)->count())->toBe(3);
});
