<?php

declare(strict_types=1);

use App\Livewire\Dashboard\Finance\Fx;
use App\Models\AuditLog;
use App\Models\FxConversion;
use App\Models\FxPair;
use App\Models\User;
use App\Services\Fx\ReportingCurrencyService;
use App\Support\Rbac\Role;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * Phase E3 → E5.2c — /dashboard/finance/fx (pairs + reporting currency):
 * strict RBAC on the route, the mount and every action; the canonical pair
 * orientation; the reporting currency with its rendered expected value, its
 * typed confirmation and its on-demand impact preview; and the invariant that
 * changing the currency recomputes nothing.
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

it('creates the canonical pair once and refuses the reverse orientation', function () {
    $page = Livewire::actingAs(userWithRole(Role::Finance))->test(Fx::class)
        ->set('pairBase', 'usd')->set('pairQuote', 'ils')->call('createPair')->assertHasNoErrors()->assertSee('ILS:USD')
        ->set('pairBase', 'ILS')->set('pairQuote', 'USD')->call('createPair')->assertHasErrors(['pair.rule']);

    expect(FxPair::count())->toBe(1);
    $pair = FxPair::query()->firstOrFail();
    expect($pair->base_currency)->toBe('USD')->and($pair->quote_currency)->toBe('ILS');
    $page->assertSee('1 USD = rate × ILS');
});

it('changes the reporting currency only with the rendered expected value and the typed code, and recomputes no frozen conversion', function () {
    config(['billing.cost_currency' => 'USD']);
    $this->travelTo(CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));
    $finance = userWithRole(Role::Finance);
    $payment = e1Payment(billingSubscriber(), ['amount' => '365.00', 'currency' => 'ILS', 'receivedAt' => CarbonImmutable::parse('2026-08-10 09:00:00', 'UTC')]);
    fxPair('USD', 'ILS');
    $rate = fxRate();
    $conversion = fxConvert('customer_payment', $payment->id, 'USD', $rate->id);
    $frozen = $conversion->only(['id', 'source_amount', 'target_amount', 'fx_rate_id', 'rate_snapshot', 'direction', 'target_currency']);

    $page = Livewire::actingAs($finance)->test(Fx::class)->assertOk()
        ->assertSet('rcExpected', 'USD')
        ->set('rcCode', 'ILS')->set('rcTyped', 'ils')->call('setReportingCurrency')->assertHasErrors(['currency.rule'])
        ->set('rcTyped', 'ILS')->set('rcExpected', 'EUR')->call('setReportingCurrency')->assertHasErrors(['currency.stale'])
        ->assertSet('rcExpected', 'USD') // the stale refusal refreshed the rendered value; nothing was written
        ->call('setReportingCurrency')->assertHasNoErrors()->assertSee('عملة التقرير الآن ILS');

    expect(app(ReportingCurrencyService::class)->current())->toBe('ILS')
        ->and(FxConversion::query()->findOrFail($conversion->id)->only(array_keys($frozen)))->toEqual($frozen)
        ->and(FxConversion::count())->toBe(1);

    $audit = AuditLog::query()->where('action', 'finance.reporting_currency_changed')->get();
    expect($audit)->toHaveCount(1)->and($audit->first()->metadata['context']['conversions_recomputed'])->toBe(0);

    $page->assertSet('rcExpected', 'ILS'); // the page re-renders on the new truth
});

it('previews the impact of a candidate currency on demand only: counts per subject type inside ONE bounded window, read-only, nothing written', function () {
    config(['billing.cost_currency' => 'USD']);
    $this->travelTo(CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));
    $ils = e1Payment(billingSubscriber(), ['amount' => '365.00', 'currency' => 'ILS', 'receivedAt' => CarbonImmutable::parse('2026-08-10 09:00:00', 'UTC')]);
    e1Payment(billingSubscriber(), ['amount' => '20.00', 'currency' => 'USD', 'receivedAt' => CarbonImmutable::parse('2026-08-11 09:00:00', 'UTC')]);
    $old = e1Payment(billingSubscriber(), ['amount' => '99.00', 'currency' => 'ILS', 'receivedAt' => CarbonImmutable::parse('2026-01-05 09:00:00', 'UTC')]); // outside the window
    fxPair('USD', 'ILS');
    $rate = fxRate();
    fxConvert('customer_payment', $ils->id, 'USD', $rate->id);

    $page = Livewire::actingAs(userWithRole(Role::Finance))->test(Fx::class)->assertOk()
        ->assertSet('impact', null)->assertDontSee('data-testid="currency-impact"', false)
        ->assertSet('impactFrom', '2026-06-09')->assertSet('impactTo', '2026-09-06'); // bounded by default

    $before = AuditLog::count();
    $page->set('rcCode', 'usd')->call('previewImpact');
    $impact = $page->get('impact');

    expect($impact['code'])->toBe('USD')->and($impact['current'])->toBe('USD')
        ->and([$impact['from'], $impact['to']])->toBe(['2026-06-09', '2026-09-06'])
        // the January payment is outside the window: 1 native + 1 converted, not 1 + 1 + 1
        ->and(collect($impact['rows'])->firstWhere('type', 'customer_payment'))->toEqual(['type' => 'customer_payment', 'native' => 1, 'converted' => 1, 'not_converted' => 0])
        ->and(collect($impact['rows'])->firstWhere('type', 'customer_refund'))->toEqual(['type' => 'customer_refund', 'native' => 0, 'converted' => 0, 'not_converted' => 0])
        ->and(AuditLog::count())->toBe($before)
        ->and(app(ReportingCurrencyService::class)->current())->toBe('USD');

    $page->assertSee('customer_payment')->assertSee('2026-06-09 → 2026-09-06');

    // Widening the window brings the older subject in — as NOT CONVERTED, never as a converted or native row.
    $page->set('impactFrom', '2026-01-01')->call('previewImpact');
    expect(collect($page->get('impact')['rows'])->firstWhere('type', 'customer_payment'))->toEqual(['type' => 'customer_payment', 'native' => 1, 'converted' => 1, 'not_converted' => 1])
        ->and($old->fresh()->currency)->toBe('ILS');

    // The window is bounded: an oversized or unparsable one is refused and no preview is shown.
    $page->set('impactFrom', '2020-01-01')->call('previewImpact')->assertHasErrors(['currency.validation'])->assertSet('impact', null);
    $page->set('impactFrom', 'not-a-date')->call('previewImpact')->assertHasErrors(['currency.validation'])->assertSet('impact', null);
});
