<?php

declare(strict_types=1);

use App\Livewire\Dashboard\Finance\Fx;
use App\Models\FxConversion;
use App\Models\FxPair;
use App\Models\FxRate;
use App\Models\User;
use App\Services\Fx\ReportingCurrencyService;
use App\Support\Rbac\Role;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * Phase E3 — /dashboard/finance/fx: strict RBAC on route, mount and every
 * action; the five operations from the page; typed currency confirmation;
 * NATIVE / CONVERTED / NOT CONVERTED with originals; no PII, no revenue.
 */
it('is reachable only with finance.fx.manage: super_admin and finance 200, operations/support/legacy admin 403, guests redirected', function () {
    rbacSync();

    $this->get(route('dashboard.finance.fx'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('dashboard.finance.fx'))->assertForbidden();
    $this->actingAs(userWithRole(Role::Operations))->get(route('dashboard.finance.fx'))->assertForbidden();
    $this->actingAs(userWithRole(Role::Support))->get(route('dashboard.finance.fx'))->assertForbidden();
    $this->actingAs(userWithRole(Role::Finance))->get(route('dashboard.finance.fx'))->assertOk();
    $this->actingAs(userWithRole(Role::SuperAdmin))->get(route('dashboard.finance.fx'))->assertOk();
    $this->actingAs(userWithRole(Role::Finance))->get(route('dashboard'))->assertSee(route('dashboard.finance.fx'));
    $this->actingAs(userWithRole(Role::Operations))->get(route('dashboard'))->assertDontSee(route('dashboard.finance.fx'));
});

it('refuses every action once the permission is withdrawn mid-session', function () {
    $finance = userWithRole(Role::Finance);
    $component = Livewire::actingAs($finance)->test(Fx::class)->assertOk()->set('pairBase', 'USD')->set('pairQuote', 'ILS')->set('rcCode', 'ILS')->set('rcTyped', 'ILS');

    $finance->removeRole(Role::Finance->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $component->call('createPair')->assertForbidden();
    expect(FxPair::count())->toBe(0);

    $component = Livewire::actingAs(userWithRole(Role::Finance))->test(Fx::class)->assertOk()->set('rcCode', 'ILS')->set('rcTyped', 'ILS');
    auth()->user()->removeRole(Role::Finance->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $component->call('setReportingCurrency')->assertForbidden();
    expect(app(ReportingCurrencyService::class)->current())->toBe(strtoupper((string) config('billing.cost_currency')));
});

it('drives pair → rate → correction → conversion → reporting currency from the page and shows the reporting view', function () {
    config(['billing.cost_currency' => 'USD']);
    $this->travelTo(CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));
    $finance = userWithRole(Role::Finance);
    $payment = e1Payment(billingSubscriber(), ['amount' => '365.00', 'currency' => 'ILS', 'receivedAt' => CarbonImmutable::parse('2026-08-10 09:00:00', 'UTC')]);
    $usd = e1Payment(billingSubscriber(), ['amount' => '20.00', 'currency' => 'USD', 'receivedAt' => CarbonImmutable::parse('2026-08-11 09:00:00', 'UTC')]);

    $page = Livewire::actingAs($finance)->test(Fx::class)
        ->set('pairBase', 'usd')->set('pairQuote', 'ils')->call('createPair')->assertHasNoErrors()->assertSee('ILS:USD')
        ->set('pairBase', 'ILS')->set('pairQuote', 'USD')->call('createPair')->assertHasErrors(['pair'])
        ->set('rateBase', 'ILS')->set('rateQuote', 'USD')->set('rateDate', '2026-08-10')->set('rateValue', '0.27')->set('rateEvidence', 'boi:2026-08-10')->call('recordRate')->assertHasErrors(['rate']) // reverse orientation
        ->set('rateBase', 'USD')->set('rateQuote', 'ILS')->set('rateValue', '3.60')->set('rateExpected', '')->call('recordRate')->assertHasNoErrors()->assertSee('سُجِّل السعر');
    $first = FxRate::query()->firstOrFail();
    expect($first->recorded_by_ref)->toBe('user:'.$finance->id);

    $page->set('rateValue', '3.65')->set('rateEvidence', 'boi:2026-08-10-fix')->set('rateExpected', '')->call('recordRate')->assertHasErrors(['rate']) // stale expectation
        ->set('rateExpected', (string) $first->id)->call('recordRate')->assertHasNoErrors()->assertSee('يستبدل #'.$first->id);
    $second = FxRate::query()->orderByDesc('id')->firstOrFail();

    $page->set('convSubjectType', 'customer_payment')->set('convSubjectId', (string) $payment->id)->set('convTarget', 'USD')->set('convRateId', (string) $first->id)->call('convert')->assertHasErrors(['conversion']) // superseded
        ->set('convRateId', (string) $second->id)->call('convert')->assertHasNoErrors()->assertSee('365.00 ILS → 100.00 USD');
    expect(FxConversion::count())->toBe(1)->and(FxConversion::query()->first()->actor_ref)->toBe('user:'.$finance->id);

    $page->set('rcCode', 'ILS')->set('rcTyped', 'ils')->call('setReportingCurrency')->assertHasErrors(['reporting_currency'])
        ->set('rcTyped', 'ILS')->call('setReportingCurrency')->assertHasNoErrors()->assertSee('عملة التقرير الآن ILS');

    $html = $this->actingAs($finance)->get(route('dashboard.finance.fx', ['from' => '2026-08-01', 'to' => '2026-08-31']))->assertOk();
    $html->assertSee('NATIVE')->assertSee('NOT CONVERTED')->assertSee('INCOMPLETE / NOT AVAILABLE')->assertSee('365.00 ILS')->assertSee('20.00 USD')
        ->assertSee('1 USD = rate × ILS')->assertSee('Revenue Recognition / Gross Profit: <strong>NOT AVAILABLE</strong>', false)
        ->assertDontSee('Gross Margin:')->assertDontSee('Revenue:');
});
