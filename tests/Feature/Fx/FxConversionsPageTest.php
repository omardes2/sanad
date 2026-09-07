<?php

declare(strict_types=1);

use App\Livewire\Dashboard\Finance\FxConversions;
use App\Livewire\Dashboard\Finance\FxConversionScopeDetail;
use App\Models\FxConversion;
use App\Models\FxConversionScope;
use App\Models\User;
use App\Support\Rbac\Role;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Phase E5.2c — /dashboard/finance/fx/conversions and
 * /fx/conversions/{scope}: loading a subject on demand (its currency, its
 * policy date, its NATIVE / CONVERTED / NOT CONVERTED state and the pointer
 * the page renders), picking ONE explicit fx_rate_id from the quotes recorded
 * for exactly that policy date, and correcting a conversion without ever
 * recomputing the revision it supersedes.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));
});

it('is reachable only with finance.fx.manage (list and detail), guests redirected', function () {
    rbacSync();
    $payment = e1Payment(billingSubscriber(), ['amount' => '365.00', 'currency' => 'ILS', 'receivedAt' => CarbonImmutable::parse('2026-08-10 09:00:00', 'UTC')]);
    fxConvert('customer_payment', $payment->id, 'USD', fxRate()->id);
    $scope = FxConversionScope::query()->firstOrFail();
    $urls = [route('dashboard.finance.fx.conversions'), route('dashboard.finance.fx.conversions.show', $scope->id)];

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

it('loads a subject on demand and states NATIVE / NOT CONVERTED / CONVERTED with the quotes of its exact policy date', function () {
    $native = e1Payment(billingSubscriber(), ['amount' => '20.00', 'currency' => 'USD', 'receivedAt' => CarbonImmutable::parse('2026-08-10 09:00:00', 'UTC')]);
    $foreign = e1Payment(billingSubscriber(), ['amount' => '365.00', 'currency' => 'ILS', 'receivedAt' => CarbonImmutable::parse('2026-08-10 09:00:00', 'UTC')]);
    $other = e1Payment(billingSubscriber(), ['amount' => '100.00', 'currency' => 'ILS', 'receivedAt' => CarbonImmutable::parse('2026-08-12 09:00:00', 'UTC')]);
    $rate = fxRate(); // USD/ILS on 2026-08-10 only

    $page = Livewire::actingAs(userWithRole(Role::Finance))->test(FxConversions::class)->assertOk()->assertSet('subject', null);

    // NATIVE: same currency as the target — never a rate-1 conversion.
    $page->set('convSubjectId', (string) $native->id)->set('convTarget', 'USD')->call('loadSubject');
    expect($page->get('subject')['status'])->toBe('NATIVE')->and($page->get('subject')['quotes'])->toBe([]);

    // NOT CONVERTED, with the quote of its own policy date offered for picking.
    $page->set('convSubjectId', (string) $foreign->id)->call('loadSubject');
    $subject = $page->get('subject');
    expect($subject['status'])->toBe('NOT CONVERTED')->and($subject['policy_date'])->toBe('2026-08-10')
        ->and($subject['quotes'])->toHaveCount(1)->and($subject['quotes'][0]['id'])->toBe($rate->id)
        ->and($page->get('expectedId'))->toBe('');

    // A subject whose policy date has no quote: nothing is borrowed from another date.
    $page->set('convSubjectId', (string) $other->id)->call('loadSubject');
    expect($page->get('subject')['policy_date'])->toBe('2026-08-12')->and($page->get('subject')['quotes'])->toBe([]);
    $page->assertSee('No quote recorded for ILS/USD on 2026-08-12');

    // CONVERTED once frozen.
    fxConvert('customer_payment', $foreign->id, 'USD', $rate->id);
    $page->set('convSubjectId', (string) $foreign->id)->call('loadSubject');
    expect($page->get('subject')['status'])->toBe('CONVERTED')->and($page->get('expectedId'))->not->toBe('');
});

it('freezes a conversion from the picked rate id only, refusing a superseded revision and a rate dated elsewhere', function () {
    $finance = userWithRole(Role::Finance);
    $payment = e1Payment(billingSubscriber(), ['amount' => '365.00', 'currency' => 'ILS', 'receivedAt' => CarbonImmutable::parse('2026-08-10 09:00:00', 'UTC')]);
    $first = fxRate();
    $second = fxRate(['rate' => '3.700000000000', 'expectedCurrentRateId' => $first->id, 'evidenceRef' => 'boi:fix']);
    $otherDate = fxRate(['rateDate' => '2026-08-11', 'rate' => '3.800000000000']);

    $page = Livewire::actingAs($finance)->test(FxConversions::class)->assertOk()
        ->set('convSubjectId', (string) $payment->id)->set('convTarget', 'USD')->call('loadSubject');

    $page->set('convRateId', (string) $first->id)->call('convert')->assertHasErrors(['conversion.stale']); // superseded revision
    $page->set('convRateId', (string) $otherDate->id)->call('convert')->assertHasErrors(['conversion.rule']); // wrong date
    expect(FxConversion::count())->toBe(0);

    $page->set('convRateId', (string) $second->id)->call('convert')->assertHasNoErrors()->assertSee('365.00 ILS → 98.65 USD');

    $conversion = FxConversion::query()->firstOrFail();
    expect(FxConversion::count())->toBe(1)->and($conversion->fx_rate_id)->toBe($second->id)
        ->and($conversion->actor_ref)->toBe('user:'.$finance->id)
        ->and($conversion->direction->value)->toBe('inverse');
});

it('corrects a conversion against the rendered pointer and keeps the superseded revision exactly as frozen', function () {
    $finance = userWithRole(Role::Finance);
    $payment = e1Payment(billingSubscriber(), ['amount' => '365.00', 'currency' => 'ILS', 'receivedAt' => CarbonImmutable::parse('2026-08-10 09:00:00', 'UTC')]);
    $first = fxRate();
    $conversion = fxConvert('customer_payment', $payment->id, 'USD', $first->id);
    $frozen = FxConversion::query()->findOrFail($conversion->id)->only(['fx_rate_id', 'rate_snapshot', 'target_amount']);
    $second = fxRate(['rate' => '3.700000000000', 'expectedCurrentRateId' => $first->id, 'evidenceRef' => 'boi:fix']);
    $scope = FxConversionScope::query()->firstOrFail();

    $page = Livewire::actingAs($finance)->test(FxConversionScopeDetail::class, ['scope' => $scope])->assertOk()
        ->assertSet('expectedId', (string) $conversion->id)->assertSet('scopeToken', 'c:'.$conversion->id)
        ->assertSee('CURRENT')->call('openConfirm')->assertSee('#'.$second->id);

    // A conversion frozen by someone else between render and submit ⇒ refused, nothing written.
    $page->set('convRateId', (string) $second->id)->set('expectedId', '999999')->call('correctConversion')
        ->assertHasErrors(['conversion.stale'])->assertSet('expectedId', (string) $conversion->id);
    expect(FxConversion::count())->toBe(1);

    $page->call('correctConversion')->assertHasNoErrors()->assertSee('تحلّ محل #'.$conversion->id);

    $new = FxConversion::query()->orderByDesc('id')->firstOrFail();
    expect(FxConversion::count())->toBe(2)
        ->and($new->supersedes_id)->toBe($conversion->id)
        ->and(FxConversionScope::query()->firstOrFail()->current_conversion_id)->toBe($new->id)
        ->and(FxConversion::query()->findOrFail($conversion->id)->only(array_keys($frozen)))->toEqual($frozen)
        ->and($new->fx_rate_id)->toBe($second->id);

    $page->assertSet('expectedId', (string) $new->id)->assertSee('SUPERSEDED');
});
