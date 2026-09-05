<?php

declare(strict_types=1);

use App\Livewire\Dashboard\Plans;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Services\Audit\AuditLogger;
use App\Support\Audit\AuditActions;
use App\Support\Rbac\Role;
use App\Support\Security\SecretRedactor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Phase D1 — every administrative change to a plan's price, currency or
 * billing period leaves an atomic audit trail (who / when / from → to), and
 * a change that cannot be audited does not happen.
 */
function planForm(array $overrides = []): array
{
    return array_merge([
        'name' => 'Plus', 'slug' => 'plus', 'description' => '', 'price' => '25.5', 'currency' => 'usd',
        'billing_period' => 'monthly', 'trial_days' => 0, 'is_active' => true, 'is_default' => false, 'sort_order' => 1,
    ], $overrides);
}

it('audits the financial fields of a newly created plan', function () {
    $admin = userWithRole(Role::SuperAdmin);

    Livewire::actingAs($admin)->test(Plans::class)->call('new')->set(planForm())->call('save')->assertHasNoErrors();

    $plan = Plan::where('slug', 'plus')->sole();
    $audit = AuditLog::where('action', AuditActions::PlanCreated)->sole();

    expect((string) $plan->price)->toBe('25.50')
        ->and($audit->subject_id)->toBe($plan->id)
        ->and($audit->user_id)->toBe($admin->id)
        ->and($audit->metadata['changes'])->toBe([
            'price' => ['from' => null, 'to' => '25.50'],
            'currency' => ['from' => null, 'to' => 'USD'],
            'billing_period' => ['from' => null, 'to' => 'monthly'],
        ])
        ->and($audit->metadata['context'])->toBe(['slug' => 'plus']);
});

it('audits price / currency / billing period changes from → to, and nothing when only non-financial fields change', function () {
    $admin = userWithRole(Role::SuperAdmin);
    $plan = billingPlan(attrs: ['slug' => 'plus', 'price' => '10.00', 'currency' => 'USD', 'billing_period' => 'monthly']);

    Livewire::actingAs($admin)->test(Plans::class)->call('edit', $plan->id)
        ->set('name', 'Plus renamed')->set('trial_days', 7)
        ->call('save')->assertHasNoErrors();

    expect(AuditLog::where('action', AuditActions::PlanFinancialsUpdated)->count())->toBe(0)
        ->and($plan->fresh()->name)->toBe('Plus renamed');

    Livewire::actingAs($admin)->test(Plans::class)->call('edit', $plan->id)
        ->set('price', '12')->set('currency', 'ils')->set('billing_period', 'yearly')
        ->call('save')->assertHasNoErrors();

    $audit = AuditLog::where('action', AuditActions::PlanFinancialsUpdated)->sole();

    expect($audit->subject_id)->toBe($plan->id)
        ->and($audit->user_id)->toBe($admin->id)
        ->and($audit->metadata['changes'])->toBe([
            'price' => ['from' => '10.00', 'to' => '12.00'],
            'currency' => ['from' => 'USD', 'to' => 'ILS'],
            'billing_period' => ['from' => 'monthly', 'to' => 'yearly'],
        ])
        ->and(collect($audit->metadata)->toJson())->not->toContain('@'); // no PII anywhere in the entry

    Livewire::actingAs($admin)->test(Plans::class)->call('edit', $plan->id)->set('price', '12.00')->call('save');

    expect(AuditLog::where('action', AuditActions::PlanFinancialsUpdated)->count())->toBe(1); // same value: no change, no audit
});

it('is atomic: when the audit entry cannot be written the price change is rolled back', function () {
    $admin = userWithRole(Role::SuperAdmin);
    $plan = billingPlan(attrs: ['slug' => 'plus', 'price' => '10.00', 'currency' => 'USD']);

    app()->instance(AuditLogger::class, new class(app(SecretRedactor::class)) extends AuditLogger
    {
        public function record(string $action, ?Model $subject = null, array $changes = [], array $context = []): AuditLog
        {
            throw new RuntimeException('audit store unavailable');
        }
    });

    try {
        Livewire::actingAs($admin)->test(Plans::class)->call('edit', $plan->id)->set('price', '99')->call('save');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('audit store unavailable');
    }

    expect((string) $plan->fresh()->price)->toBe('10.00')
        ->and(AuditLog::where('action', AuditActions::PlanFinancialsUpdated)->count())->toBe(0);
});

it('rejects a price that is not a plain decimal with at most two places', function (string $price) {
    Livewire::actingAs(userWithRole(Role::SuperAdmin))->test(Plans::class)->call('new')->set(planForm(['price' => $price]))->call('save')->assertHasErrors(['price']);

    expect(Plan::count())->toBe(0);
})->with(['1e3', '1.234', '-1', 'abc']);
