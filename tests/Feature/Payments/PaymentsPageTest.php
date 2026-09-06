<?php

declare(strict_types=1);

use App\Livewire\Dashboard\Finance\Payments;
use App\Models\CustomerPayment;
use App\Models\User;
use App\Support\Rbac\Role;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * Phase E1 → E5.2a — /dashboard/finance/payments: strict RBAC on the route,
 * the mount and every action; Record Manual Payment through the service with
 * one attempt key per attempt (idempotent double submit); allowlisted,
 * bounded, URL-kept filters; 25-row pagination in id-desc order; no PII;
 * no revenue / gross profit figure.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));
});

it('is reachable only with finance.payments.manage: super_admin and finance 200, operations/support/legacy admin 403, guests redirected', function () {
    rbacSync();

    $this->get(route('dashboard.finance.payments'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('dashboard.finance.payments'))->assertForbidden();
    $this->actingAs(userWithRole(Role::Operations))->get(route('dashboard.finance.payments'))->assertForbidden();
    $this->actingAs(userWithRole(Role::Support))->get(route('dashboard.finance.payments'))->assertForbidden();
    $this->actingAs(userWithRole(Role::Finance))->get(route('dashboard.finance.payments'))->assertOk();
    $this->actingAs(userWithRole(Role::SuperAdmin))->get(route('dashboard.finance.payments'))->assertOk();
});

it('shows the payments and refunds nav links only to accounts holding finance.payments.manage', function () {
    $this->actingAs(userWithRole(Role::Finance))->get(route('dashboard'))->assertOk()->assertSee(route('dashboard.finance.payments'))->assertSee(route('dashboard.finance.refunds'));
    $this->actingAs(userWithRole(Role::Operations))->get(route('dashboard'))->assertOk()->assertDontSee(route('dashboard.finance.payments'))->assertDontSee(route('dashboard.finance.refunds'));
    $this->actingAs(userWithRole(Role::Support))->get(route('dashboard'))->assertOk()->assertDontSee(route('dashboard.finance.payments'));
});

it('refuses every action for an account without the permission, even after the page was opened (server-side re-authorization)', function () {
    $finance = userWithRole(Role::Finance);
    $subscriber = billingSubscriber();
    $component = Livewire::actingAs($finance)->test(Payments::class)->assertOk()
        ->set('subscriberId', (string) $subscriber->id)->set('amount', '10.00')->set('paymentCurrency', 'USD');

    $finance->removeRole(Role::Finance->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $component->call('recordPayment')->assertForbidden();
    expect(CustomerPayment::count())->toBe(0);
});

it('records a manual payment from the form with one attempt key: a failed attempt keeps the key, a double submit with the same key is idempotent, success rotates the key; FEES UNKNOWN and no PII', function () {
    $finance = userWithRole(Role::Finance);
    $subscriber = billingSubscriber();
    $component = Livewire::actingAs($finance)->test(Payments::class)->assertOk();
    $key = $component->get('idempotencyKey');
    expect($key)->toStartWith('ui:');

    // 1. a refused attempt (bad amount) keeps the SAME attempt key — the user fixes the payload and resubmits the same attempt.
    $component->set('subscriberId', (string) $subscriber->id)->set('amount', 'abc')->set('paymentCurrency', 'USD')
        ->call('recordPayment')->assertHasErrors(['payment.rule'])->assertSee('REFUSED BY SERVICE');
    expect($component->get('idempotencyKey'))->toBe($key)->and(CustomerPayment::count())->toBe(0);

    // 2. success ⇒ one payment; the key rotates only now.
    $component->set('amount', '10.00')->call('recordPayment')->assertHasNoErrors()->assertSee('سُجِّلت الدفعة');
    $payment = CustomerPayment::query()->firstOrFail();
    $newKey = $component->get('idempotencyKey');
    expect($payment->idempotency_key)->toBe($key)->and($newKey)->not->toBe($key)->and($payment->gateway_fee_amount)->toBeNull()->and($payment->recorded_by_ref)->toBe('user:'.$finance->id);

    // 3. the same key + the same facts replayed through the service ⇒ the same row, nothing new (what a double click produces).
    $component->set('idempotencyKey', $key)->set('subscriberId', (string) $subscriber->id)->set('amount', '10.00')->set('paymentCurrency', 'USD')->set('receivedAt', $payment->received_at->utc()->format('Y-m-d\TH:i'))
        ->call('recordPayment')->assertHasNoErrors()->assertSee('مسجَّلة مسبقًا');
    expect(CustomerPayment::count())->toBe(1);

    // 4. the same key + different facts ⇒ IDEMPOTENCY CONFLICT, shown as such; the UI never mints a new key by itself.
    $component->set('idempotencyKey', $key)->set('subscriberId', (string) $subscriber->id)->set('amount', '11.00')->set('paymentCurrency', 'USD')
        ->call('recordPayment')->assertHasErrors(['payment.conflict'])->assertSee('IDEMPOTENCY CONFLICT');
    expect(CustomerPayment::count())->toBe(1)->and($component->get('idempotencyKey'))->toBe($key);

    $html = $this->actingAs($finance)->get(route('dashboard.finance.payments', ['from' => '2026-09-01', 'to' => '2026-09-06']))->assertOk()
        ->assertSee('FEES UNKNOWN')->assertSee('#'.$subscriber->id)->assertSee(route('dashboard.finance.payments.show', $payment->id))
        ->assertSee('Received (UTC)')->assertSee('timezone')
        ->assertDontSee($subscriber->email)->assertDontSee($subscriber->name)->assertDontSee('Revenue:')->assertDontSee('Gross Profit:')->assertDontSee('Margin:')
        ->getContent();
    expect(substr_count($html, 'data-testid="payment-'))->toBe(1);
});

it('separates error kinds: validation (UI parsing) and REFUSED BY SERVICE with the rule name, writing nothing', function () {
    $finance = userWithRole(Role::Finance);
    $subscriber = billingSubscriber();
    $component = Livewire::actingAs($finance)->test(Payments::class);

    $component->set('subscriberId', 'x')->set('amount', '10.00')->set('paymentCurrency', 'USD')->call('recordPayment')->assertHasErrors(['payment.validation'])->assertHasNoErrors(['payment.rule']);
    $component->set('subscriberId', (string) $subscriber->id)->set('amount', '10.00')->set('paymentCurrency', 'USD')->set('receivedAt', '2026-09-07T10:00')->call('recordPayment')->assertHasErrors(['payment.rule'])->assertSee('received_at —');
    $component->set('receivedAt', '2026-09-06T10:00')->set('feeCurrency', 'ILS')->set('gatewayFeeAmount', '1.00')->call('recordPayment')->assertHasErrors(['payment.rule']);
    expect(CustomerPayment::count())->toBe(0);
});

it('filters are allowlisted, bounded and kept in the URL; a filter change resets the page; 25 rows per page in id-desc order', function () {
    $finance = userWithRole(Role::Finance);
    $a = billingSubscriber();
    $b = billingSubscriber();
    for ($i = 0; $i < 30; $i++) {
        e1Payment($a, ['amount' => '1.00', 'currency' => 'USD', 'receivedAt' => CarbonImmutable::parse('2026-08-10', 'UTC')->addMinutes($i)]);
    }
    $ils = e1Payment($b, ['amount' => '365.00', 'currency' => 'ILS', 'receivedAt' => CarbonImmutable::parse('2026-08-20', 'UTC'), 'gatewayFeeAmount' => '3.65', 'feeCurrency' => 'ILS']);
    e1Payment($b, ['amount' => '5.00', 'currency' => 'USD', 'receivedAt' => CarbonImmutable::parse('2026-07-01', 'UTC')]); // outside the window

    $page = Livewire::actingAs($finance)->test(Payments::class, ['from' => '2026-08-01', 'to' => '2026-08-31']);
    $html = $page->html();
    expect(substr_count($html, 'data-testid="payment-'))->toBe(25)->and($html)->toContain('31 rows · page 1 of 2')
        ->and(strpos($html, 'data-testid="payment-'.$ils->id.'"'))->toBeLessThan((int) strpos($html, 'data-testid="payment-'.($ils->id - 1).'"')); // id desc

    $page->call('gotoPage', 2)->assertSee('page 2 of 2');
    expect(substr_count($page->html(), 'data-testid="payment-'))->toBe(6);

    // a filter change resets to page 1
    $page->set('currency', 'ILS')->assertSee('1 rows · page 1 of 1')->assertSee('data-testid="payment-'.$ils->id.'"', false);
    $page->set('currency', '')->set('fee', 'known')->assertSee('1 rows · page 1 of 1');
    $page->set('fee', 'unknown')->assertSee('30 rows · page 1 of 2');
    $page->set('fee', '')->set('subscriber', (string) $b->id)->assertSee('1 rows · page 1 of 1');
    $page->set('subscriber', '')->set('status', 'succeeded')->assertSee('31 rows');
    $page->set('status', 'weird')->assertSee('31 rows'); // outside the allowlist ⇒ ignored
    $page->set('gateway', 'manual')->assertSee('31 rows');
    $page->set('gateway', 'DROP TABLE')->assertSee('31 rows'); // ignored
    $page->set('gateway', '')->set('subscriber', 'abc')->assertSee('31 rows'); // ignored

    // bounded window
    $page->set('subscriber', '')->set('from', '2025-01-01')->set('to', '2026-08-31')->assertSee('النطاق الأقصى')->assertSee('0 rows');

    // URL persistence
    $this->actingAs($finance)->get(route('dashboard.finance.payments', ['from' => '2026-08-01', 'to' => '2026-08-31', 'currency' => 'ILS', 'fee' => 'known']))->assertOk()->assertSee('1 rows · page 1 of 1');
});
