<?php

declare(strict_types=1);

use App\Exceptions\Payments\PaymentRuleException;
use App\Models\CustomerPayment;
use App\Models\CustomerRefund;
use App\Models\PaymentAllocation;
use App\Models\RefundAllocation;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Services\Payments\AllocationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Phase E1 temporal rules under a FROZEN clock (never flaky):
 *  amount / refund / allocation / refund allocation > 0;
 *  received_at ≤ now; refunded_at ≤ now; refunded_at ≥ received_at;
 *  allocation timestamps are server-generated (no caller input at all);
 *  backdating a real old payment is allowed — the future never is.
 */
const E1_NOW = '2026-09-06 12:00:00';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse(E1_NOW, 'UTC'));
});

function e1Rule(callable $fn): string
{
    try {
        $fn();
    } catch (PaymentRuleException $e) {
        return $e->rule;
    }

    return 'none';
}

it('accepts received_at exactly at now and any real past date, and refuses one second in the future', function () {
    $subscriber = billingSubscriber();
    $now = CarbonImmutable::parse(E1_NOW, 'UTC');

    $atNow = e1Payment($subscriber, ['receivedAt' => $now]);
    $old = e1Payment($subscriber, ['receivedAt' => CarbonImmutable::parse('2025-01-15 08:30:00', 'UTC')]); // backdated real payment: allowed

    expect($atNow->received_at->equalTo($now))->toBeTrue()
        ->and($old->received_at->format('Y-m-d'))->toBe('2025-01-15')
        ->and(e1Rule(fn () => e1Payment($subscriber, ['receivedAt' => $now->addSecond()])))->toBe('received_at')
        ->and(e1Rule(fn () => e1Payment($subscriber, ['receivedAt' => $now->addMinutes(2)])))->toBe('received_at') // no skew tolerance
        ->and(CustomerPayment::count())->toBe(2);
});

it('accepts refunded_at within [received_at, now] and refuses before the payment or after now', function () {
    $received = CarbonImmutable::parse('2026-09-01 10:00:00', 'UTC');
    $now = CarbonImmutable::parse(E1_NOW, 'UTC');
    $payment = e1Payment(billingSubscriber(), ['receivedAt' => $received]);

    expect(e1Rule(fn () => e1Refund($payment, ['amount' => '1.00', 'refundedAt' => $received->subSecond()])))->toBe('refunded_at')
        ->and(e1Rule(fn () => e1Refund($payment, ['amount' => '1.00', 'refundedAt' => $now->addSecond()])))->toBe('refunded_at')
        ->and(CustomerRefund::count())->toBe(0);

    $atReceipt = e1Refund($payment, ['amount' => '1.00', 'refundedAt' => $received]);
    $atNow = e1Refund($payment, ['amount' => '1.00', 'refundedAt' => $now]);
    expect($atReceipt->refunded_at->equalTo($received))->toBeTrue()->and($atNow->refunded_at->equalTo($now))->toBeTrue();
});

it('requires strictly positive amounts for payments, refunds, allocations and refund allocations', function () {
    $subscriber = billingSubscriber();
    $subscription = Subscription::create(['subscriber_id' => $subscriber->id, 'plan_id' => billingPlan()->id, 'status' => 'active', 'started_at' => now()]);
    $event = SubscriptionEvent::query()->create(['subscription_id' => $subscription->id, 'subscriber_id' => $subscriber->id, 'event_type' => 'extended', 'from_status' => 'active', 'to_status' => 'active', 'to_period_start' => CarbonImmutable::parse('2026-09-01', 'UTC'), 'to_period_end' => CarbonImmutable::parse('2026-10-01', 'UTC'), 'effective_at' => now(), 'source' => 'admin', 'actor_ref' => 'console']);
    $payment = e1Payment($subscriber, ['amount' => '10.00']);
    $allocation = app(AllocationService::class)->allocatePayment($payment->id, $event->id, '5.00');
    $refund = e1Refund($payment, ['amount' => '5.00']);
    $a = app(AllocationService::class);

    foreach (['0', '0.00', '-1', '-0.01', 'abc', ''] as $bad) {
        expect(e1Rule(fn () => e1Payment($subscriber, ['amount' => $bad])))->toBe('amount', "payment {$bad}")
            ->and(e1Rule(fn () => e1Refund($payment, ['amount' => $bad])))->toBe('amount', "refund {$bad}")
            ->and(e1Rule(fn () => $a->allocatePayment($payment->id, $event->id, $bad)))->toBe('amount', "allocation {$bad}")
            ->and(e1Rule(fn () => $a->allocateRefund($refund->id, $allocation->id, $bad)))->toBe('amount', "refund allocation {$bad}");
    }

    expect(e1Rule(fn () => e1Payment($subscriber, ['amount' => '0.01'])))->toBe('none')
        ->and(CustomerPayment::count())->toBe(2)->and(CustomerRefund::count())->toBe(1)->and(PaymentAllocation::count())->toBe(1)->and(RefundAllocation::count())->toBe(0);
});

it('generates allocation timestamps on the server: callers cannot pass one, and the stored value is exactly the frozen server clock', function () {
    $subscriber = billingSubscriber();
    $subscription = Subscription::create(['subscriber_id' => $subscriber->id, 'plan_id' => billingPlan()->id, 'status' => 'active', 'started_at' => now()]);
    $event = SubscriptionEvent::query()->create(['subscription_id' => $subscription->id, 'subscriber_id' => $subscriber->id, 'event_type' => 'extended', 'from_status' => 'active', 'to_status' => 'active', 'to_period_start' => CarbonImmutable::parse('2026-09-01', 'UTC'), 'to_period_end' => CarbonImmutable::parse('2026-10-01', 'UTC'), 'effective_at' => now(), 'source' => 'admin', 'actor_ref' => 'console']);
    $payment = e1Payment($subscriber, ['amount' => '10.00']);
    $refund = e1Refund($payment, ['amount' => '5.00']);

    // No timestamp parameter exists on either signature.
    foreach (['allocatePayment', 'allocateRefund'] as $method) {
        $params = array_map(fn (ReflectionParameter $p) => $p->getName(), (new ReflectionMethod(AllocationService::class, $method))->getParameters());
        expect($params)->not->toContain('allocatedAt', 'at', 'timestamp', 'occurredAt', 'createdAt', 'when')
            ->and(array_filter($params, fn (string $n) => preg_match('/at$|time|date/i', $n) === 1))->toBe([], $method);
    }

    $frozen = CarbonImmutable::parse(E1_NOW, 'UTC');
    $allocation = app(AllocationService::class)->allocatePayment($payment->id, $event->id, '5.00');
    $reversal = app(AllocationService::class)->allocateRefund($refund->id, $allocation->id, '5.00');

    expect($allocation->allocated_at->equalTo($frozen))->toBeTrue()
        ->and($allocation->created_at->equalTo($frozen))->toBeTrue()
        ->and($reversal->allocated_at->equalTo($frozen))->toBeTrue()
        ->and($reversal->created_at->equalTo($frozen))->toBeTrue();
});
