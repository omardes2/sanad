<?php

declare(strict_types=1);

use App\Livewire\Dashboard\Finance\FxRates;
use App\Livewire\Dashboard\Finance\FxRateScopeDetail;
use App\Models\FxConversion;
use App\Models\FxRate;
use App\Models\FxRateScope;
use App\Models\User;
use App\Support\Rbac\Role;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Phase E5.2c — /dashboard/finance/fx/rates and /fx/rates/{scope}: the quote
 * list with its bounded UTC window, the first quote for a date, and the
 * append-only correction on the scope page with the pointer the page
 * rendered. A quote belongs to its date; a correction never touches a frozen
 * conversion.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));
});

it('is reachable only with finance.fx.manage (list and detail), guests redirected', function () {
    rbacSync();
    fxRate();
    $scope = FxRateScope::query()->firstOrFail();

    $urls = [route('dashboard.finance.fx.rates'), route('dashboard.finance.fx.rates.show', $scope->id)];

    foreach ($urls as $url) {
        $this->get($url)->assertRedirect(route('login'));
    }

    foreach ($urls as $url) {
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get($url)->assertForbidden();
        $this->actingAs(userWithRole(Role::Operations))->get($url)->assertForbidden();
        $this->actingAs(userWithRole(Role::Support))->get($url)->assertForbidden();
        $this->actingAs(userWithRole(Role::Finance))->get($url)->assertOk();
        $this->actingAs(userWithRole(Role::SuperAdmin))->get($url)->assertOk();
    }
});

it('lists one row per (pair, date) scope with its current revision, filtered by pair and a bounded UTC window', function () {
    fxRate(); // USD/ILS 2026-08-10
    fxRate(['rateDate' => '2026-08-11', 'rate' => '3.700000000000']);
    fxRate(['baseCurrency' => 'EUR', 'quoteCurrency' => 'USD', 'rateDate' => '2026-08-10', 'rate' => '1.100000000000']);

    $page = Livewire::actingAs(userWithRole(Role::Finance))->test(FxRates::class, ['from' => '2026-08-01', 'to' => '2026-08-31'])->assertOk();
    $page->assertSee('ILS:USD')->assertSee('EUR:USD')->assertSee('3.650000000000')->assertSee('1.100000000000');

    $page->set('pair', 'EUR:USD')->assertSee('1.100000000000')->assertDontSee('3.650000000000');
    $page->set('pair', '')->set('from', '2026-08-11')->assertSee('3.700000000000')->assertDontSee('1.100000000000');

    // An unparsable window lists nothing — never everything.
    $page->set('from', 'not-a-date')->assertSee('صيغة التاريخ غير صالحة')->assertDontSee('3.700000000000');
    // A window wider than the cap is refused with the same "nothing listed" rule.
    $page->set('from', '2020-01-01')->set('to', '2026-08-31')->assertSee('النافذة الأقصى');
});

it('records the first quote for a date and refuses a date that already has one as STATE CHANGED, writing nothing', function () {
    $finance = userWithRole(Role::Finance);
    fxPair('USD', 'ILS');

    $page = Livewire::actingAs($finance)->test(FxRates::class)
        ->set('rateBase', 'ILS')->set('rateQuote', 'USD')->set('rateDate', '2026-08-10')->set('rateValue', '0.27')->set('rateEvidence', 'boi:2026-08-10')
        ->call('recordRate')->assertHasErrors(['rate.rule']); // reverse orientation is refused, never flipped

    expect(FxRate::count())->toBe(0);

    $page->set('rateBase', 'USD')->set('rateQuote', 'ILS')->set('rateValue', '3.60')->call('recordRate')->assertHasNoErrors()->assertSee('سُجِّل السعر');
    $first = FxRate::query()->firstOrFail();
    expect($first->recorded_by_ref)->toBe('user:'.$finance->id)->and($first->rateDate())->toBe('2026-08-10');

    $page->set('rateValue', '3.65')->set('rateEvidence', 'boi:2026-08-10-fix')->call('recordRate')
        ->assertHasErrors(['rate.stale'])->assertSee('State changed');

    expect(FxRate::count())->toBe(1)->and(FxRate::query()->firstOrFail()->rate)->toBe($first->rate);
});

it('corrects a quote on its scope page against the rendered pointer, keeps every revision, and never recomputes a frozen conversion', function () {
    $finance = userWithRole(Role::Finance);
    $payment = e1Payment(billingSubscriber(), ['amount' => '365.00', 'currency' => 'ILS', 'receivedAt' => CarbonImmutable::parse('2026-08-10 09:00:00', 'UTC')]);
    $first = fxRate();
    $conversion = fxConvert('customer_payment', $payment->id, 'USD', $first->id);
    $frozen = $conversion->only(['fx_rate_id', 'rate_snapshot', 'source_amount', 'target_amount']);
    $scope = FxRateScope::query()->firstOrFail();

    $page = Livewire::actingAs($finance)->test(FxRateScopeDetail::class, ['scope' => $scope])->assertOk()
        ->assertSet('expectedId', (string) $first->id)->assertSet('scopeToken', 'x:'.$first->id)
        ->assertSee('CURRENT')->assertSee('#'.$conversion->id); // the frozen conversion on this revision is listed

    // A revision recorded by someone else between render and submit ⇒ refused, nothing written.
    $page->set('rateValue', '3.70')->set('rateEvidence', 'boi:fix')->set('expectedId', '999999')->call('correctRate')
        ->assertHasErrors(['rate.stale'])->assertSet('expectedId', (string) $first->id);
    expect(FxRate::count())->toBe(1);

    $page->call('correctRate')->assertHasNoErrors()->assertSee('تحلّ محل #'.$first->id);
    $second = FxRate::query()->orderByDesc('id')->firstOrFail();

    expect(FxRate::count())->toBe(2)
        ->and($second->supersedes_id)->toBe($first->id)
        ->and(FxRateScope::query()->firstOrFail()->current_rate_id)->toBe($second->id)
        ->and(FxRate::query()->findOrFail($first->id)->rate)->toBe($first->rate) // the superseded revision is untouched
        ->and(FxConversion::query()->findOrFail($conversion->id)->only(array_keys($frozen)))->toEqual($frozen)
        ->and(FxConversion::count())->toBe(1);

    $page->assertSet('expectedId', (string) $second->id)->assertSee('SUPERSEDED');
});
