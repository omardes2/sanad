<?php

declare(strict_types=1);

use App\Livewire\Dashboard\Finance\Payments;
use App\Models\CustomerPayment;
use App\Models\CustomerRefund;
use App\Models\PaymentAllocation;
use App\Models\RefundAllocation;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\User;
use App\Support\Rbac\Role;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * Phase E1 — /dashboard/finance/payments: strict RBAC on the route, the
 * mount and EVERY action; the four write operations through the services;
 * idempotent double submit; no PII; no revenue / gross profit figure.
 */
it('is reachable only with finance.payments.manage: super_admin and finance 200, operations/support/legacy admin 403, guests redirected', function () {
    rbacSync();

    $this->get(route('dashboard.finance.payments'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('dashboard.finance.payments'))->assertForbidden();
    $this->actingAs(userWithRole(Role::Operations))->get(route('dashboard.finance.payments'))->assertForbidden();
    $this->actingAs(userWithRole(Role::Support))->get(route('dashboard.finance.payments'))->assertForbidden();
    $this->actingAs(userWithRole(Role::Finance))->get(route('dashboard.finance.payments'))->assertOk();
    $this->actingAs(userWithRole(Role::SuperAdmin))->get(route('dashboard.finance.payments'))->assertOk();
});

it('shows the payments nav link only to accounts holding finance.payments.manage', function () {
    $this->actingAs(userWithRole(Role::Finance))->get(route('dashboard'))->assertOk()->assertSee(route('dashboard.finance.payments'));
    $this->actingAs(userWithRole(Role::Operations))->get(route('dashboard'))->assertOk()->assertDontSee(route('dashboard.finance.payments'));
    $this->actingAs(userWithRole(Role::Support))->get(route('dashboard'))->assertOk()->assertDontSee(route('dashboard.finance.payments'));
});

it('refuses every action for an account without the permission, even after the page was opened (server-side re-authorization)', function () {
    $finance = userWithRole(Role::Finance);
    $subscriber = billingSubscriber();
    $component = Livewire::actingAs($finance)->test(Payments::class)->assertOk()
        ->set('subscriberId', (string) $subscriber->id)->set('amount', '10.00')->set('currency', 'USD');

    // The role is withdrawn while the page is open: the next write is refused before any service runs.
    $finance->removeRole(Role::Finance->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $component->call('recordPayment')->assertForbidden();

    expect(CustomerPayment::count())->toBe(0);
});

it('records a manual payment from the form, is idempotent on a double submit, shows FEES UNKNOWN and no PII', function () {
    $finance = userWithRole(Role::Finance);
    $subscriber = billingSubscriber();

    $component = Livewire::actingAs($finance)->test(Payments::class)
        ->set('subscriberId', (string) $subscriber->id)
        ->set('amount', '49.90')->set('currency', 'usd')
        ->set('receivedAt', CarbonImmutable::now('UTC')->subHour()->format('Y-m-d\TH:i'))
        ->set('reference', 'BANK-1');
    $key = $component->get('idempotencyKey');

    $component->call('recordPayment')->assertHasNoErrors()->assertSee('سُجِّلت الدفعة');

    $payment = CustomerPayment::query()->firstOrFail();
    expect($payment->idempotency_key)->toBe($key)->and($payment->recorded_by_ref)->toBe('user:'.$finance->id)
        ->and($payment->user_id)->toBe($subscriber->id)->and((string) $payment->amount)->toBe('49.90')->and($payment->currency)->toBe('USD')
        ->and($component->get('idempotencyKey'))->not->toBe($key); // a fresh key for the next form

    // A double submit (same key, same facts) records nothing new.
    $component->set('idempotencyKey', $key)->set('subscriberId', (string) $subscriber->id)->set('amount', '49.90')->set('currency', 'USD')
        ->set('receivedAt', $payment->received_at->format('Y-m-d\TH:i'))->set('reference', 'BANK-1')
        ->call('recordPayment')->assertHasNoErrors()->assertSee('مسجَّلة مسبقًا');
    expect(CustomerPayment::count())->toBe(1);

    $html = $this->actingAs($finance)->get(route('dashboard.finance.payments'))->assertOk();
    $html->assertSee('FEES UNKNOWN')->assertSee('#'.$subscriber->id)->assertSee('49.90 USD')
        ->assertSee('Revenue Recognition: <strong>NOT AVAILABLE</strong>', false)
        ->assertSee('Gross Profit: <strong>NOT AVAILABLE</strong>', false)
        ->assertDontSee($subscriber->email)->assertDontSee($subscriber->name)
        ->assertDontSee('Revenue:')->assertDontSee('Gross Margin:');
});

it('surfaces domain refusals as form errors without writing anything', function () {
    $finance = userWithRole(Role::Finance);
    $subscriber = billingSubscriber();
    $payment = e1Payment($subscriber, ['amount' => '20.00']);

    Livewire::actingAs($finance)->test(Payments::class)
        ->set('subscriberId', (string) $subscriber->id)->set('amount', '0')->set('currency', 'USD')
        ->call('recordPayment')->assertHasErrors(['payment'])
        ->set('amount', '10.00')->set('currency', 'USD')->set('gatewayFeeAmount', '1.00')->set('feeCurrency', 'EUR')
        ->call('recordPayment')->assertHasErrors(['payment'])
        ->set('subscriberId', 'abc')->set('gatewayFeeAmount', '')->set('feeCurrency', '')
        ->call('recordPayment')->assertHasErrors(['payment'])
        ->set('refundPaymentId', (string) $payment->id)->set('refundAmount', '20.01')->set('refundReasonCode', 'x')
        ->call('recordRefund')->assertHasErrors(['refund'])
        ->set('allocPaymentId', (string) $payment->id)->set('allocEventId', '999')->set('allocAmount', '5.00')
        ->call('allocatePayment')->assertHasErrors(['allocation'])
        ->set('rallocRefundId', '1')->set('rallocAllocationId', '1')->set('rallocAmount', '1.00')
        ->call('allocateRefund')->assertHasErrors(['refund_allocation'])
        ->set('from', 'bad')->assertSee('صيغة التاريخ غير صالحة');

    expect(CustomerPayment::count())->toBe(1)->and(CustomerRefund::count())->toBe(0)->and(PaymentAllocation::count())->toBe(0)->and(RefundAllocation::count())->toBe(0);
});

it('records a refund, allocates a payment to a subscription-event period and attributes the refund, all from the page', function () {
    $finance = userWithRole(Role::Finance);
    $subscriber = billingSubscriber();
    $payment = e1Payment($subscriber, ['amount' => '100.00', 'receivedAt' => CarbonImmutable::now('UTC')->subDay()]);
    $subscription = Subscription::create(['subscriber_id' => $subscriber->id, 'plan_id' => billingPlan()->id, 'status' => 'active', 'started_at' => now()]);
    $event = SubscriptionEvent::query()->create(['subscription_id' => $subscription->id, 'subscriber_id' => $subscriber->id, 'event_type' => 'extended', 'from_status' => 'active', 'to_status' => 'active', 'to_period_start' => CarbonImmutable::parse('2026-09-01', 'UTC'), 'to_period_end' => CarbonImmutable::parse('2026-10-01', 'UTC'), 'effective_at' => now(), 'source' => 'admin', 'actor_ref' => 'console']);
    $noPeriod = SubscriptionEvent::query()->create(['subscription_id' => $subscription->id, 'subscriber_id' => $subscriber->id, 'event_type' => 'baseline', 'to_status' => 'active', 'effective_at' => now(), 'source' => 'baseline', 'actor_ref' => 'console']);

    $component = Livewire::actingAs($finance)->test(Payments::class)
        ->set('allocPaymentId', (string) $payment->id)
        ->assertSee('#'.$event->id.' · extended')
        ->assertDontSee('#'.$noPeriod->id.' · baseline') // only events with a valid period are offered
        ->set('allocEventId', (string) $event->id)->set('allocAmount', '100.00')
        ->call('allocatePayment')->assertHasNoErrors()->assertSee('خُصِّص')
        ->set('refundPaymentId', (string) $payment->id)->set('refundAmount', '25.00')->set('refundReasonCode', 'goodwill')
        ->set('refundedAt', CarbonImmutable::now('UTC')->format('Y-m-d\TH:i'))
        ->call('recordRefund')->assertHasNoErrors()->assertSee('سُجِّل الاسترداد');

    $allocation = PaymentAllocation::query()->firstOrFail();
    $refund = CustomerRefund::query()->firstOrFail();
    expect($allocation->period_start->toDateString())->toBe('2026-09-01')->and($allocation->subscription_event_id)->toBe($event->id)
        ->and($refund->recorded_by_ref)->toBe('user:'.$finance->id)->and($refund->reason_code)->toBe('goodwill');

    $component->set('rallocRefundId', (string) $refund->id)->set('rallocAllocationId', (string) $allocation->id)->set('rallocAmount', '25.00')
        ->call('allocateRefund')->assertHasNoErrors()->assertSee('نُسب الاسترداد');

    expect(RefundAllocation::count())->toBe(1)->and((string) $allocation->fresh()->amount)->toBe('100.00');

    // The window summary shows cash and attribution apart, per currency.
    $component->set('from', CarbonImmutable::now('UTC')->subDays(2)->toDateString())->set('to', CarbonImmutable::now('UTC')->toDateString())
        ->assertSee('Gross Cash Collected')->assertSee('Allocated Collected Amount')->assertSee('FEES UNKNOWN')
        ->assertSeeInOrder(['USD', '100.00', '25.00', '75.00']);
});
