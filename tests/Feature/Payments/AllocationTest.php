<?php

declare(strict_types=1);

use App\Enums\CustomerPaymentEventType;
use App\Enums\PaymentSource;
use App\Exceptions\Payments\ImmutableFinancialRecordException;
use App\Exceptions\Payments\PaymentRuleException;
use App\Models\AuditLog;
use App\Models\CustomerPayment;
use App\Models\PaymentAllocation;
use App\Models\RefundAllocation;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\SubscriptionService;
use App\Services\Payments\AllocationService;
use App\Services\Payments\CashCollectedQuery;
use App\Services\Payments\CustomerPaymentService;
use App\Support\Audit\AuditActions;
use App\Support\Billing\SubscriptionStateToken;
use App\Support\Security\SecretRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Phase E1 — attribution of collected cash to subscription service periods:
 * the period is ALWAYS the to_period snapshot of one subscription_events row
 * (never typed), same subscriber, same currency, Σ ≤ payment, append-only;
 * refunds are attributed through refund_allocations without touching the
 * original allocation. Attribution only — never revenue.
 */
function e1Event(User $subscriber, ?CarbonImmutable $start = null, ?CarbonImmutable $end = null, string $type = 'extended'): SubscriptionEvent
{
    $subscription = Subscription::query()->where('subscriber_id', $subscriber->id)->first()
        ?? Subscription::create(['subscriber_id' => $subscriber->id, 'plan_id' => billingPlan()->id, 'status' => 'active', 'started_at' => now()]);

    return SubscriptionEvent::query()->create([
        'subscription_id' => $subscription->id, 'subscriber_id' => $subscriber->id, 'event_type' => $type,
        'from_status' => 'active', 'to_status' => 'active', 'to_period_start' => $start, 'to_period_end' => $end,
        'effective_at' => now(), 'source' => 'admin', 'actor_ref' => 'console',
    ]);
}

function allocations(): AllocationService
{
    return app(AllocationService::class);
}

it('allocates a succeeded payment to the exact period of ONE real subscription event (snapshot copied, never typed) and audits it', function () {
    $plan = billingPlan(attrs: ['price' => '30.00', 'currency' => 'USD']);
    $subscriber = billingSubscriber($plan);
    $subscription = Subscription::query()->where('subscriber_id', $subscriber->id)->firstOrFail();
    // A genuine E0 event with a period snapshot: extend by 30 days.
    app(SubscriptionService::class)->extend($subscription, 30, SubscriptionStateToken::for($subscription));
    $event = SubscriptionEvent::query()->where('subscription_id', $subscription->id)->orderByDesc('id')->firstOrFail();
    $payment = e1Payment($subscriber, ['amount' => '30.00']);

    $allocation = allocations()->allocatePayment($payment->id, $event->id, '30.00', 'renewal');

    expect($allocation->subscription_event_id)->toBe($event->id)
        ->and($allocation->subscription_id)->toBe($subscription->id)
        ->and($allocation->subscriber_id)->toBe($subscriber->id)
        ->and($allocation->period_start->equalTo($event->to_period_start))->toBeTrue()
        ->and($allocation->period_end->equalTo($event->to_period_end))->toBeTrue()
        ->and($allocation->currency)->toBe('USD')
        ->and((string) $allocation->amount)->toBe('30.00')
        ->and($allocation->actor_ref)->toBe('console')
        ->and(AuditLog::where('action', AuditActions::PaymentAllocated)->count())->toBe(1)
        ->and(AuditLog::where('action', AuditActions::PaymentAllocated)->first()->metadata['context']['subscription_event_id'])->toBe($event->id);
});

it('rejects an event without a valid period, an event of another subscriber, a non-existent event, and a payment that did not succeed', function () {
    $subscriber = billingSubscriber();
    $payment = e1Payment($subscriber);
    $baseline = e1Event($subscriber, null, null, 'baseline'); // no period snapshot
    $inverted = e1Event($subscriber, CarbonImmutable::parse('2026-10-01', 'UTC'), CarbonImmutable::parse('2026-09-01', 'UTC'));
    $zero = e1Event($subscriber, CarbonImmutable::parse('2026-10-01', 'UTC'), CarbonImmutable::parse('2026-10-01', 'UTC'));
    $foreign = e1Event(billingSubscriber(), CarbonImmutable::parse('2026-09-01', 'UTC'), CarbonImmutable::parse('2026-10-01', 'UTC'));

    $rule = function (int $paymentId, int $eventId, string $amount = '10.00'): string {
        try {
            allocations()->allocatePayment($paymentId, $eventId, $amount);
        } catch (PaymentRuleException $e) {
            return $e->rule;
        }

        return 'none';
    };

    expect($rule($payment->id, $baseline->id))->toBe('period')
        ->and($rule($payment->id, $inverted->id))->toBe('period')
        ->and($rule($payment->id, $zero->id))->toBe('period')
        ->and($rule($payment->id, $foreign->id))->toBe('subscriber_mismatch')
        ->and($rule($payment->id, 999999))->toBe('subscription_event')
        ->and($rule($payment->id, $foreign->id, '0'))->toBe('amount');

    $pending = e1PendingPaymentFor($subscriber);
    $valid = e1Event($subscriber, CarbonImmutable::parse('2026-09-01', 'UTC'), CarbonImmutable::parse('2026-10-01', 'UTC'));
    expect($rule($pending->id, $valid->id))->toBe('lifecycle')
        ->and(PaymentAllocation::count())->toBe(0)
        ->and(AuditLog::where('action', AuditActions::PaymentAllocated)->count())->toBe(0);
});

function e1PendingPaymentFor(User $subscriber): CustomerPayment
{
    return CustomerPayment::query()->create([
        'subscriber_id' => $subscriber->id, 'gateway' => 'manual', 'idempotency_key' => 'pending-'.str()->random(6), 'amount' => '100.00', 'currency' => 'USD',
        'received_at' => CarbonImmutable::now('UTC')->subHour(), 'current_status' => 'created', 'recorded_by_ref' => 'console',
    ]);
}

it('spreads one payment over several events but never beyond its amount (fully accepted or fully refused)', function () {
    $subscriber = billingSubscriber();
    $payment = e1Payment($subscriber, ['amount' => '100.00']);
    $sep = e1Event($subscriber, CarbonImmutable::parse('2026-09-01', 'UTC'), CarbonImmutable::parse('2026-10-01', 'UTC'));
    $oct = e1Event($subscriber, CarbonImmutable::parse('2026-10-01', 'UTC'), CarbonImmutable::parse('2026-11-01', 'UTC'));

    allocations()->allocatePayment($payment->id, $sep->id, '60.00');
    allocations()->allocatePayment($payment->id, $oct->id, '30.00');

    expect(fn () => allocations()->allocatePayment($payment->id, $oct->id, '10.01'))->toThrow(PaymentRuleException::class, 'يتجاوز مبلغ الدفعة');
    expect(PaymentAllocation::query()->where('customer_payment_id', $payment->id)->sum('amount'))->toEqual(90);

    allocations()->allocatePayment($payment->id, $oct->id, '10.00'); // exactly the remainder
    expect(PaymentAllocation::query()->where('customer_payment_id', $payment->id)->count())->toBe(3);
});

it('attributes a refund to an allocation append-only: ≤ refund, ≤ allocation, same currency, same payment; the original allocation is untouched', function () {
    $subscriber = billingSubscriber();
    $payment = e1Payment($subscriber, ['amount' => '100.00']);
    $event = e1Event($subscriber, CarbonImmutable::parse('2026-09-01', 'UTC'), CarbonImmutable::parse('2026-10-01', 'UTC'));
    $a1 = allocations()->allocatePayment($payment->id, $event->id, '70.00');
    $a2 = allocations()->allocatePayment($payment->id, $event->id, '30.00');
    $refund = e1Refund($payment, ['amount' => '50.00']);

    $ra = allocations()->allocateRefund($refund->id, $a2->id, '30.00', 'partial');
    expect($ra->currency)->toBe('USD')->and((string) $ra->amount)->toBe('30.00')->and($ra->actor_ref)->toBe('console')
        ->and(AuditLog::where('action', AuditActions::RefundAllocated)->count())->toBe(1);

    $rule = function (int $refundId, int $allocationId, string $amount): string {
        try {
            allocations()->allocateRefund($refundId, $allocationId, $amount);
        } catch (PaymentRuleException $e) {
            return $e->rule;
        }

        return 'none';
    };

    expect($rule($refund->id, $a2->id, '0.01'))->toBe('allocation_reversal_limit') // a2 is fully reversed
        ->and($rule($refund->id, $a1->id, '20.01'))->toBe('refund_allocation_limit') // only 20 of the refund is left
        ->and($rule($refund->id, $a1->id, '0'))->toBe('amount');

    $otherPayment = e1Payment(billingSubscriber(), ['amount' => '10.00']);
    $otherRefund = e1Refund($otherPayment, ['amount' => '5.00']);
    expect($rule($otherRefund->id, $a1->id, '1.00'))->toBe('allocation'); // allocation belongs to another payment

    allocations()->allocateRefund($refund->id, $a1->id, '20.00');
    expect(RefundAllocation::query()->where('customer_refund_id', $refund->id)->sum('amount'))->toEqual(50)
        ->and((string) $a1->fresh()->amount)->toBe('70.00') // never modified
        ->and((string) $a2->fresh()->amount)->toBe('30.00')
        ->and(PaymentAllocation::count())->toBe(2);
});

it('is append-only for both allocation tables and atomic with the audit entry', function () {
    $subscriber = billingSubscriber();
    $payment = e1Payment($subscriber);
    $event = e1Event($subscriber, CarbonImmutable::parse('2026-09-01', 'UTC'), CarbonImmutable::parse('2026-10-01', 'UTC'));
    $allocation = allocations()->allocatePayment($payment->id, $event->id, '10.00');
    $refund = e1Refund($payment, ['amount' => '10.00']);
    $ra = allocations()->allocateRefund($refund->id, $allocation->id, '5.00');

    expect(fn () => $allocation->forceFill(['amount' => '1.00'])->save())->toThrow(ImmutableFinancialRecordException::class)
        ->and(fn () => $allocation->delete())->toThrow(ImmutableFinancialRecordException::class)
        ->and(fn () => $ra->forceFill(['amount' => '1.00'])->save())->toThrow(ImmutableFinancialRecordException::class)
        ->and(fn () => $ra->delete())->toThrow(ImmutableFinancialRecordException::class);

    app()->instance(AuditLogger::class, new class(app(SecretRedactor::class)) extends AuditLogger
    {
        public function record(string $action, ?Model $subject = null, array $changes = [], array $context = []): AuditLog
        {
            throw new RuntimeException('audit store unavailable');
        }
    });

    expect(fn () => allocations()->allocatePayment($payment->id, $event->id, '10.00'))->toThrow(RuntimeException::class);
    expect(fn () => allocations()->allocateRefund($refund->id, $allocation->id, '5.00'))->toThrow(RuntimeException::class);
    expect(PaymentAllocation::count())->toBe(1)->and(RefundAllocation::count())->toBe(1)
        ->and(AuditLog::where('action', AuditActions::PaymentAllocated)->count())->toBe(1)
        ->and(AuditLog::where('action', AuditActions::RefundAllocated)->count())->toBe(1);
});

it('refuses a new allocation once the payment is disputed, while its collected cash stays in history', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));
    $subscriber = billingSubscriber();
    $payment = e1Payment($subscriber, ['amount' => '100.00', 'receivedAt' => CarbonImmutable::parse('2026-08-10', 'UTC')]);
    $event = e1Event($subscriber, CarbonImmutable::parse('2026-08-01', 'UTC'), CarbonImmutable::parse('2026-09-01', 'UTC'));
    allocations()->allocatePayment($payment->id, $event->id, '10.00');

    app(CustomerPaymentService::class)->transition($payment, CustomerPaymentEventType::Disputed, $payment->stateToken(), PaymentSource::Gateway, 'chargeback');

    $summary = app(CashCollectedQuery::class)->summarise(CarbonImmutable::parse('2026-08-01', 'UTC'), CarbonImmutable::parse('2026-09-01', 'UTC'))['USD'];
    expect(fn () => allocations()->allocatePayment($payment->fresh()->id, $event->id, '10.00'))->toThrow(PaymentRuleException::class, 'succeeded الآن')
        ->and(PaymentAllocation::count())->toBe(1)
        ->and($summary->grossCashCollected)->toBe('100.00')
        ->and($summary->allocatedCollectedAmount)->toBe('10.00');
});

it('caps refund allocations on ONE payment allocation across ALL refunds: Σ over every refund ≤ the allocation amount', function () {
    $subscriber = billingSubscriber();
    $payment = e1Payment($subscriber, ['amount' => '100.00']);
    $event = e1Event($subscriber, CarbonImmutable::parse('2026-09-01', 'UTC'), CarbonImmutable::parse('2026-10-01', 'UTC'));
    $allocation = allocations()->allocatePayment($payment->id, $event->id, '30.00');
    $refundA = e1Refund($payment, ['amount' => '25.00']);
    $refundB = e1Refund($payment, ['amount' => '25.00']);
    $refundC = e1Refund($payment, ['amount' => '25.00']);

    allocations()->allocateRefund($refundA->id, $allocation->id, '20.00'); // 20 of 30 used by refund A

    $rule = function (int $refundId, string $amount) use ($allocation): string {
        try {
            allocations()->allocateRefund($refundId, $allocation->id, $amount);
        } catch (PaymentRuleException $e) {
            return $e->rule;
        }

        return 'none';
    };

    // Refund B alone has 25 available, but the ALLOCATION only has 10 left across all refunds.
    expect($rule($refundB->id, '15.00'))->toBe('allocation_reversal_limit')
        ->and($rule($refundB->id, '10.00'))->toBe('none') // exactly the remainder
        ->and($rule($refundC->id, '0.01'))->toBe('allocation_reversal_limit') // fully reversed now, whatever refund asks
        ->and(RefundAllocation::query()->where('payment_allocation_id', $allocation->id)->sum('amount'))->toEqual(30)
        ->and(RefundAllocation::query()->where('payment_allocation_id', $allocation->id)->distinct()->count('customer_refund_id'))->toBe(2)
        ->and((string) $allocation->fresh()->amount)->toBe('30.00');
});
