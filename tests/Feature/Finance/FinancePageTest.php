<?php

declare(strict_types=1);

use App\Enums\CostSource;
use App\Enums\SubscriptionStatus;
use App\Livewire\Dashboard\Finance;
use App\Models\FinanceMrrSnapshot;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Rbac\Role;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * Phase D2 — /dashboard/finance: strict RBAC, three clearly separated
 * sections, calculated-only vocabulary, UTC labelling, no PII, no gross
 * profit number, marker rows and system rows kept apart.
 */
function financePageFixture(): array
{
    $usd = billingPlan(attrs: ['slug' => 'usd-monthly', 'price' => '10.00', 'currency' => 'USD', 'billing_period' => 'monthly']);
    $alice = billingSubscriber($usd);
    $bob = billingSubscriber($usd, ['status' => SubscriptionStatus::PastDue]);
    Subscription::create(['subscriber_id' => billingSubscriber(null)->id, 'plan_id' => null, 'status' => SubscriptionStatus::Active, 'started_at' => now()]);

    // Ledger rows inside the default 30-day window.
    financeRow(['user_id' => $alice->id, 'subscriber_id' => $alice->id, 'plan_id' => $usd->id, 'plan_slug' => 'usd-monthly', 'total_cost' => '0.250000', 'provider_cost' => '0.250000', 'channel' => 'whatsapp', 'occurred_at' => CarbonImmutable::now('UTC')->subDays(2)]);
    financeRow(['user_id' => $bob->id, 'subscriber_id' => $bob->id, 'plan_id' => $usd->id, 'plan_slug' => 'usd-monthly', 'total_cost' => '0.000000', 'cost_source' => CostSource::None, 'occurred_at' => CarbonImmutable::now('UTC')->subDays(3)]);
    financeRow(['total_cost' => '0.400000', 'provider_cost' => '0.400000', 'operation' => 'health_check', 'channel' => 'admin', 'occurred_at' => CarbonImmutable::now('UTC')->subDay()]);

    // One snapshot yesterday, none today.
    FinanceMrrSnapshot::create([
        'snapshot_date' => CarbonImmutable::now('UTC')->subDay()->toDateString(), 'captured_at' => CarbonImmutable::now('UTC')->subDay(), 'currency' => 'USD',
        'plan_id' => $usd->id, 'plan_key' => "plan:{$usd->id}", 'plan_slug' => 'usd-monthly', 'plan_price' => '10.00', 'billing_period' => 'monthly',
        'active_count' => 1, 'trialing_count' => 0, 'past_due_count' => 1, 'mrr_normalized' => '10.000000', 'calculation_version' => 1,
    ]);

    return [$usd, $alice, $bob];
}

it('is reachable only with finance.view: super_admin and finance 200, operations/support/legacy admin 403, guests redirected', function () {
    rbacSync();

    $this->get(route('dashboard.finance'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('dashboard.finance'))->assertForbidden();
    $this->actingAs(userWithRole(Role::Operations))->get(route('dashboard.finance'))->assertForbidden();
    $this->actingAs(userWithRole(Role::Support))->get(route('dashboard.finance'))->assertForbidden();
    $this->actingAs(userWithRole(Role::Finance))->get(route('dashboard.finance'))->assertOk();
    $this->actingAs(userWithRole(Role::SuperAdmin))->get(route('dashboard.finance'))->assertOk();
});

it('shows the finance nav link only to accounts holding finance.view', function () {
    $this->actingAs(userWithRole(Role::Finance))->get(route('dashboard'))->assertOk()->assertSee(route('dashboard.finance'));
    $this->actingAs(userWithRole(Role::Operations))->get(route('dashboard'))->assertOk()->assertDontSee(route('dashboard.finance'));
    $this->actingAs(userWithRole(Role::Support))->get(route('dashboard'))->assertOk()->assertDontSee(route('dashboard.finance'));
});

it('renders the three sections with calculated-only wording, UTC labels, current KPIs, past_due wording and the marker apart', function () {
    [$usd, $alice] = financePageFixture();

    $response = $this->actingAs(userWithRole(Role::Finance))->get(route('dashboard.finance'))->assertOk();

    $response
        ->assertSee('Current Subscription Run-rate — as of')
        ->assertSee('Usage &amp; Cost Analysis — selected UTC window', false)
        ->assertSee('MRR Snapshot History')
        ->assertSee('Historical revenue: <strong>NOT AVAILABLE</strong>', false)
        ->assertSee('NOT AVAILABLE — Phase E')
        ->assertSee('revenue_history_unavailable')
        ->assertSee('Current Calculated MRR')
        ->assertSee('10.000000') // MRR: one active USD subscriber; past_due excluded
        ->assertSee('Subscriptions with past_due status: 1')
        ->assertSee('اشتراكات بحالة Past Due')
        ->assertSee('No plan (not a currency, never revenue): active 1')
        ->assertSee('Window (UTC)')
        ->assertSee('Known Provider Cost')
        ->assertSee('0.650000 USD') // 0.25 + 0.40 known; unpriced not summed
        ->assertSee('NO PRODUCER')
        ->assertSee('COMMUNICATION COST COVERAGE INCOMPLETE')
        ->assertSee('EXTERNAL COST: NO PRODUCER')
        ->assertSee('تكلفة النظام غير المنسوبة')
        ->assertSee('#'.$alice->id)
        ->assertSee('NOT CAPTURED')
        ->assertSee('NOT AVAILABLE')
        ->assertDontSee('XXX')
        ->assertDontSee('Collected Revenue')
        ->assertDontSee('Reconciled Margin')
        ->assertDontSee('Gross Profit:')
        ->assertDontSee('مستحق غير مدفوع')
        ->assertDontSee($alice->email)
        ->assertDontSee($alice->name);
});

it('never renders a gross profit figure: the margin card carries a status and reasons only', function () {
    financePageFixture();

    $html = $this->actingAs(userWithRole(Role::SuperAdmin))->get(route('dashboard.finance'))->getContent();
    $card = substr($html, strpos($html, 'data-testid="gross-margin"'), 1200);

    expect($card)->toContain('NOT AVAILABLE — Phase E')
        ->and($card)->toContain('revenue_history_unavailable')
        ->and($card)->toContain('incomplete_cost_coverage')
        ->and($card)->toContain('unpriced_usage')
        ->and(preg_match('/\d+\.\d{6}/', $card))->toBe(0); // no amount in the card at all
});

it('shows ARPU as N/A when no subscription is active and keeps currencies apart', function () {
    $usd = billingPlan(attrs: ['price' => '10.00', 'currency' => 'USD']);
    $ils = billingPlan(attrs: ['price' => '35.00', 'currency' => 'ILS']);
    billingSubscriber($usd, ['status' => SubscriptionStatus::PastDue]); // USD: past_due only → active 0
    billingSubscriber($ils);

    $response = $this->actingAs(userWithRole(Role::Finance))->get(route('dashboard.finance'))->assertOk();
    $html = $response->getContent();
    $usdCard = substr($html, strpos($html, 'data-testid="current-USD"'), 1500);

    expect($usdCard)->toContain('N/A')->and($usdCard)->toContain('Subscriptions with past_due status: 1');
    $response->assertSee('data-testid="current-ILS"', false)->assertSee('35.000000')->assertDontSee('45.000000');
});

it('keeps the current run-rate independent of the usage window and applies the window filters to costs only', function () {
    [$usd, $alice] = financePageFixture();
    $from = CarbonImmutable::now('UTC')->subDays(60)->toDateString();
    $to = CarbonImmutable::now('UTC')->subDays(40)->toDateString();

    Livewire::actingAs(userWithRole(Role::Finance))
        ->test(Finance::class, ['from' => $from, 'to' => $to])
        ->assertSee('Current Calculated MRR')
        ->assertSee('10.000000') // still the as-of-now MRR even though the window has no usage
        ->assertSee('0.000000 USD') // no known cost in that window
        ->assertSee('لا مشتركين في هذا النطاق.');

    Livewire::actingAs(userWithRole(Role::Finance))
        ->test(Finance::class)
        ->set('attribution', 'system')
        ->assertSee('0.400000 USD')
        ->assertDontSee('#'.$alice->id);
});

it('rejects an invalid window with a message and no data section', function () {
    Livewire::actingAs(userWithRole(Role::Finance))
        ->test(Finance::class, ['from' => '2026-01-01', 'to' => '2026-06-30'])
        ->assertSee('النطاق الأقصى')
        ->assertDontSee('Window (UTC)');
});

it('shows the subscriber link only with subscribers.view and exposes the CSV link only with finance.export', function () {
    [, $alice] = financePageFixture();

    $this->actingAs(userWithRole(Role::Finance))->get(route('dashboard.finance'))
        ->assertSee(route('dashboard.subscribers.show', $alice->id))
        ->assertSee(route('dashboard.finance.export', ['from' => CarbonImmutable::now('UTC')->subDays(29)->toDateString()], false), false);

    // A super_admin without subscribers.view cannot exist (Gate::before) — verify the link logic with a finance user whose role lost the permission.
    // Create the user first: userWithRole() re-syncs the matrix and would re-grant.
    $viewer = userWithRole(Role::Finance);
    $role = Spatie\Permission\Models\Role::findByName(Role::Finance->value);
    $role->revokePermissionTo('subscribers.view');
    $role->revokePermissionTo('finance.export');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($viewer->fresh())->get(route('dashboard.finance'))
        ->assertOk()
        ->assertDontSee(route('dashboard.subscribers.show', $alice->id))
        ->assertDontSee('dashboard/finance/export');
});
