<?php

declare(strict_types=1);

use App\Enums\CustomerPaymentEventType;
use App\Enums\PaymentSource;
use App\Exceptions\Payments\ImmutableFinancialRecordException;
use App\Exceptions\Payments\PaymentConflictException;
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
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

    $allocation = allocations()->allocatePayment($payment->id, $event->id, '30.00', e1Key(), 'renewal');

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
            allocations()->allocatePayment($paymentId, $eventId, $amount, e1Key());
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

    allocations()->allocatePayment($payment->id, $sep->id, '60.00', e1Key());
    allocations()->allocatePayment($payment->id, $oct->id, '30.00', e1Key());

    expect(fn () => allocations()->allocatePayment($payment->id, $oct->id, '10.01', e1Key()))->toThrow(PaymentRuleException::class, 'يتجاوز مبلغ الدفعة');
    expect(PaymentAllocation::query()->where('customer_payment_id', $payment->id)->sum('amount'))->toEqual(90);

    allocations()->allocatePayment($payment->id, $oct->id, '10.00', e1Key()); // exactly the remainder
    expect(PaymentAllocation::query()->where('customer_payment_id', $payment->id)->count())->toBe(3);
});

it('attributes a refund to an allocation append-only: ≤ refund, ≤ allocation, same currency, same payment; the original allocation is untouched', function () {
    $subscriber = billingSubscriber();
    $payment = e1Payment($subscriber, ['amount' => '100.00']);
    $event = e1Event($subscriber, CarbonImmutable::parse('2026-09-01', 'UTC'), CarbonImmutable::parse('2026-10-01', 'UTC'));
    $a1 = allocations()->allocatePayment($payment->id, $event->id, '70.00', e1Key());
    $a2 = allocations()->allocatePayment($payment->id, $event->id, '30.00', e1Key());
    $refund = e1Refund($payment, ['amount' => '50.00']);

    $ra = allocations()->allocateRefund($refund->id, $a2->id, '30.00', e1Key(), 'partial');
    expect($ra->currency)->toBe('USD')->and((string) $ra->amount)->toBe('30.00')->and($ra->actor_ref)->toBe('console')
        ->and(AuditLog::where('action', AuditActions::RefundAllocated)->count())->toBe(1);

    $rule = function (int $refundId, int $allocationId, string $amount): string {
        try {
            allocations()->allocateRefund($refundId, $allocationId, $amount, e1Key());
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

    allocations()->allocateRefund($refund->id, $a1->id, '20.00', e1Key());
    expect(RefundAllocation::query()->where('customer_refund_id', $refund->id)->sum('amount'))->toEqual(50)
        ->and((string) $a1->fresh()->amount)->toBe('70.00') // never modified
        ->and((string) $a2->fresh()->amount)->toBe('30.00')
        ->and(PaymentAllocation::count())->toBe(2);
});

it('is append-only for both allocation tables and atomic with the audit entry', function () {
    $subscriber = billingSubscriber();
    $payment = e1Payment($subscriber);
    $event = e1Event($subscriber, CarbonImmutable::parse('2026-09-01', 'UTC'), CarbonImmutable::parse('2026-10-01', 'UTC'));
    $allocation = allocations()->allocatePayment($payment->id, $event->id, '10.00', e1Key());
    $refund = e1Refund($payment, ['amount' => '10.00']);
    $ra = allocations()->allocateRefund($refund->id, $allocation->id, '5.00', e1Key());

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

    expect(fn () => allocations()->allocatePayment($payment->id, $event->id, '10.00', e1Key()))->toThrow(RuntimeException::class);
    expect(fn () => allocations()->allocateRefund($refund->id, $allocation->id, '5.00', e1Key()))->toThrow(RuntimeException::class);
    expect(PaymentAllocation::count())->toBe(1)->and(RefundAllocation::count())->toBe(1)
        ->and(AuditLog::where('action', AuditActions::PaymentAllocated)->count())->toBe(1)
        ->and(AuditLog::where('action', AuditActions::RefundAllocated)->count())->toBe(1);
});

it('refuses a new allocation once the payment is disputed, while its collected cash stays in history', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));
    $subscriber = billingSubscriber();
    $payment = e1Payment($subscriber, ['amount' => '100.00', 'receivedAt' => CarbonImmutable::parse('2026-08-10', 'UTC')]);
    $event = e1Event($subscriber, CarbonImmutable::parse('2026-08-01', 'UTC'), CarbonImmutable::parse('2026-09-01', 'UTC'));
    allocations()->allocatePayment($payment->id, $event->id, '10.00', e1Key());

    app(CustomerPaymentService::class)->transition($payment, CustomerPaymentEventType::Disputed, $payment->stateToken(), PaymentSource::Gateway, 'chargeback');

    $summary = app(CashCollectedQuery::class)->summarise(CarbonImmutable::parse('2026-08-01', 'UTC'), CarbonImmutable::parse('2026-09-01', 'UTC'))['USD'];
    expect(fn () => allocations()->allocatePayment($payment->fresh()->id, $event->id, '10.00', e1Key()))->toThrow(PaymentRuleException::class, 'succeeded الآن')
        ->and(PaymentAllocation::count())->toBe(1)
        ->and($summary->grossCashCollected)->toBe('100.00')
        ->and($summary->allocatedCollectedAmount)->toBe('10.00');
});

it('caps refund allocations on ONE payment allocation across ALL refunds: Σ over every refund ≤ the allocation amount', function () {
    $subscriber = billingSubscriber();
    $payment = e1Payment($subscriber, ['amount' => '100.00']);
    $event = e1Event($subscriber, CarbonImmutable::parse('2026-09-01', 'UTC'), CarbonImmutable::parse('2026-10-01', 'UTC'));
    $allocation = allocations()->allocatePayment($payment->id, $event->id, '30.00', e1Key());
    $refundA = e1Refund($payment, ['amount' => '25.00']);
    $refundB = e1Refund($payment, ['amount' => '25.00']);
    $refundC = e1Refund($payment, ['amount' => '25.00']);

    allocations()->allocateRefund($refundA->id, $allocation->id, '20.00', e1Key()); // 20 of 30 used by refund A

    $rule = function (int $refundId, string $amount) use ($allocation): string {
        try {
            allocations()->allocateRefund($refundId, $allocation->id, $amount, e1Key());
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

/*
 * E5.2a — durable, service-level allocation idempotency (no cache involved anywhere below).
 */
function e1AllocRule(callable $fn): string
{
    try {
        $fn();
    } catch (PaymentRuleException $e) {
        return $e->rule;
    }

    return 'none';
}
it('requires an opaque bounded idempotency key for EVERY new payment allocation and refund allocation: the parameter is non-nullable and an empty / oversized / multi-line key is refused before anything is written', function () {
    $subscriber = billingSubscriber();
    $payment = e1Payment($subscriber, ['amount' => '100.00']);
    $event = e1Event($subscriber, CarbonImmutable::parse('2026-09-01', 'UTC'), CarbonImmutable::parse('2026-10-01', 'UTC'));
    $allocation = allocations()->allocatePayment($payment->id, $event->id, '10.00', e1Key());
    $refund = e1Refund($payment, ['amount' => '10.00']);

    foreach (['allocatePayment', 'allocateRefund'] as $method) {
        $param = (new ReflectionMethod(AllocationService::class, $method))->getParameters()[3];
        expect($param->getName())->toBe('idempotencyKey')
            ->and($param->isOptional())->toBeFalse($method)
            ->and($param->allowsNull())->toBeFalse($method)
            ->and((string) $param->getType())->toBe('string');
    }

    foreach (['', '   ', str_repeat('k', 192), "a\nb", "tab\tkey"] as $bad) {
        expect(e1AllocRule(fn () => allocations()->allocatePayment($payment->id, $event->id, '10.00', $bad)))->toBe('idempotency_key', json_encode($bad))
            ->and(e1AllocRule(fn () => allocations()->allocateRefund($refund->id, $allocation->id, '5.00', $bad)))->toBe('idempotency_key', json_encode($bad));
    }

    expect(PaymentAllocation::count())->toBe(1)->and(RefundAllocation::count())->toBe(0)
        ->and(AuditLog::where('action', AuditActions::PaymentAllocated)->count())->toBe(1)
        ->and(AuditLog::where('action', AuditActions::RefundAllocated)->count())->toBe(0);

    // a 191-character key is accepted and stored verbatim (trimmed)
    $max = str_repeat('m', 191);
    $stored = allocations()->allocatePayment($payment->id, $event->id, '1.00', ' '.$max.' ');
    expect($stored->idempotency_key)->toBe($max)->and(mb_strlen($stored->idempotency_key))->toBe(191);
});

it('payment allocation: same key + same facts ⇒ the SAME row (no new row, no new audit, cap untouched); same key + any different fact ⇒ PaymentConflictException with nothing written', function () {
    $subscriber = billingSubscriber();
    $payment = e1Payment($subscriber, ['amount' => '100.00']);
    $other = e1Payment($subscriber, ['amount' => '100.00']);
    $sep = e1Event($subscriber, CarbonImmutable::parse('2026-09-01', 'UTC'), CarbonImmutable::parse('2026-10-01', 'UTC'));
    $oct = e1Event($subscriber, CarbonImmutable::parse('2026-10-01', 'UTC'), CarbonImmutable::parse('2026-11-01', 'UTC'));
    $key = 'ui:'.str()->uuid();
    $audits = fn () => AuditLog::where('action', AuditActions::PaymentAllocated)->count();

    $first = allocations()->allocatePayment($payment->id, $sep->id, '20.00', $key, 'renewal');
    expect($first->wasRecentlyCreated)->toBeTrue()->and($first->idempotency_key)->toBe($key)->and($audits())->toBe(1);

    $replay = allocations()->allocatePayment($payment->id, $sep->id, '20.00', $key, 'renewal');
    expect($replay->wasRecentlyCreated)->toBeFalse()->and($replay->id)->toBe($first->id)
        ->and(PaymentAllocation::count())->toBe(1)->and($audits())->toBe(1);

    // every fact that defines the operation is compared, not only the amount
    foreach ([
        'amount' => [$payment->id, $sep->id, '25.00', 'renewal'],
        'target event / period' => [$payment->id, $oct->id, '20.00', 'renewal'],
        'parent payment' => [$other->id, $sep->id, '20.00', 'renewal'],
        'reason_code' => [$payment->id, $sep->id, '20.00', 'upgrade'],
        'reason_code null' => [$payment->id, $sep->id, '20.00', null],
    ] as $changed => [$p, $e, $amount, $reason]) {
        expect(fn () => allocations()->allocatePayment($p, $e, $amount, $key, $reason))->toThrow(PaymentConflictException::class, 'بحقائق مختلفة', $changed);
    }
    expect(PaymentAllocation::count())->toBe(1)->and($audits())->toBe(1)->and((string) $first->fresh()->amount)->toBe('20.00');

    // the cap is unchanged: a replay never "re-counts" and never clips; a NEW key is bounded exactly as before
    allocations()->allocatePayment($payment->id, $sep->id, '80.00', e1Key());
    expect(allocations()->allocatePayment($payment->id, $sep->id, '20.00', $key, 'renewal')->id)->toBe($first->id) // still a replay once the payment is fully allocated
        ->and(fn () => allocations()->allocatePayment($payment->id, $sep->id, '0.01', e1Key()))->toThrow(PaymentRuleException::class, 'يتجاوز مبلغ الدفعة')
        ->and(PaymentAllocation::count())->toBe(2)->and($audits())->toBe(2);
});

it('refund allocation: same key + same facts ⇒ the SAME row without a new audit; a different amount, target allocation, refund or reason under the same key ⇒ conflict, nothing written, caps unchanged', function () {
    $subscriber = billingSubscriber();
    $payment = e1Payment($subscriber, ['amount' => '100.00']);
    $event = e1Event($subscriber, CarbonImmutable::parse('2026-09-01', 'UTC'), CarbonImmutable::parse('2026-10-01', 'UTC'));
    $a1 = allocations()->allocatePayment($payment->id, $event->id, '70.00', e1Key());
    $a2 = allocations()->allocatePayment($payment->id, $event->id, '30.00', e1Key());
    $refund = e1Refund($payment, ['amount' => '50.00']);
    $refund2 = e1Refund($payment, ['amount' => '10.00']);
    $key = 'ui:'.str()->uuid();
    $audits = fn () => AuditLog::where('action', AuditActions::RefundAllocated)->count();

    $first = allocations()->allocateRefund($refund->id, $a1->id, '20.00', $key, 'partial');
    $replay = allocations()->allocateRefund($refund->id, $a1->id, '20.00', $key, 'partial');
    expect($first->wasRecentlyCreated)->toBeTrue()->and($replay->wasRecentlyCreated)->toBeFalse()->and($replay->id)->toBe($first->id)
        ->and($first->idempotency_key)->toBe($key)->and(RefundAllocation::count())->toBe(1)->and($audits())->toBe(1);

    foreach ([
        'amount' => [$refund->id, $a1->id, '25.00', 'partial'],
        'target allocation' => [$refund->id, $a2->id, '20.00', 'partial'],
        'refund' => [$refund2->id, $a1->id, '20.00', 'partial'],
        'reason_code' => [$refund->id, $a1->id, '20.00', null],
    ] as $changed => [$r, $a, $amount, $reason]) {
        expect(fn () => allocations()->allocateRefund($r, $a, $amount, $key, $reason))->toThrow(PaymentConflictException::class, 'بحقائق مختلفة', $changed);
    }
    expect(RefundAllocation::count())->toBe(1)->and($audits())->toBe(1)
        ->and(e1AllocRule(fn () => allocations()->allocateRefund($refund->id, $a1->id, '30.01', e1Key())))->toBe('refund_allocation_limit') // 20 used of 50: the cap counts the row once
        ->and(e1AllocRule(fn () => allocations()->allocateRefund($refund->id, $a1->id, '30.00', e1Key())))->toBe('none')
        ->and(RefundAllocation::query()->where('customer_refund_id', $refund->id)->sum('amount'))->toEqual(50);
});

it('DB uniqueness is the authority: a second row with the same idempotency key cannot be inserted on either table, while historical rows without a key (NULL) coexist freely', function () {
    $subscriber = billingSubscriber();
    $payment = e1Payment($subscriber, ['amount' => '100.00']);
    $event = e1Event($subscriber, CarbonImmutable::parse('2026-09-01', 'UTC'), CarbonImmutable::parse('2026-10-01', 'UTC'));
    $allocation = allocations()->allocatePayment($payment->id, $event->id, '10.00', 'dup-key');
    $refund = e1Refund($payment, ['amount' => '10.00']);
    $reversal = allocations()->allocateRefund($refund->id, $allocation->id, '5.00', 'dup-rkey');

    $row = fn (PaymentAllocation $a, ?string $key) => ['customer_payment_id' => $a->customer_payment_id, 'subscription_event_id' => $a->subscription_event_id, 'subscription_id' => $a->subscription_id, 'subscriber_id' => $a->subscriber_id, 'period_start' => $a->period_start, 'period_end' => $a->period_end, 'amount' => '1.00', 'currency' => 'USD', 'allocated_at' => now(), 'actor_ref' => 'test', 'idempotency_key' => $key, 'created_at' => now()];
    $rrow = fn (RefundAllocation $r, ?string $key) => ['customer_refund_id' => $r->customer_refund_id, 'payment_allocation_id' => $r->payment_allocation_id, 'amount' => '1.00', 'currency' => 'USD', 'allocated_at' => now(), 'actor_ref' => 'test', 'idempotency_key' => $key, 'created_at' => now()];

    // each attempt in its own savepoint: on PostgreSQL a failed statement aborts the surrounding transaction
    expect(fn () => DB::transaction(fn () => DB::table('payment_allocations')->insert($row($allocation, 'dup-key'))))->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('refund_allocations')->insert($rrow($reversal, 'dup-rkey'))))->toThrow(UniqueConstraintViolationException::class);

    // pre-E5.2a rows carry NULL: two of them never collide (nullable column, no backfill needed)
    DB::table('payment_allocations')->insert($row($allocation, null));
    DB::table('payment_allocations')->insert($row($allocation, null));
    DB::table('refund_allocations')->insert($rrow($reversal, null));
    DB::table('refund_allocations')->insert($rrow($reversal, null));
    expect(PaymentAllocation::count())->toBe(3)->and(RefundAllocation::count())->toBe(3)
        ->and(PaymentAllocation::query()->whereNull('idempotency_key')->count())->toBe(2)
        ->and(Schema::hasIndex('payment_allocations', 'payment_allocations_idempotency_key_unique'))->toBeTrue()
        ->and(Schema::hasIndex('refund_allocations', 'refund_allocations_idempotency_key_unique'))->toBeTrue();
});

it('survives the unique race path exactly like RefundService: when the same-key row appears between the pre-check and the insert, the savepoint catches the violation, the existing row is compared and returned (same facts) or refused (different facts), and the outer transaction stays usable', function () {
    $subscriber = billingSubscriber();
    $payment = e1Payment($subscriber, ['amount' => '100.00']);
    $event = e1Event($subscriber, CarbonImmutable::parse('2026-09-01', 'UTC'), CarbonImmutable::parse('2026-10-01', 'UTC'));
    $key = 'race-'.str()->random(6);
    $audits = fn () => AuditLog::where('action', AuditActions::PaymentAllocated)->count();

    // Simulate the loser of a PostgreSQL race: a same-key row is committed by "another process" after this process's
    // pre-check and cap query (the SUM over payment_allocations) and before its INSERT — i.e. outside the savepoint.
    $inject = function (string $amount) use ($payment, $event, &$key): void {
        $done = false;
        DB::listen(function (QueryExecuted $q) use (&$done, $payment, $event, &$key, $amount): void {
            if ($done || ! str_contains($q->sql, 'SUM(ROUND(amount * 100))') || ! str_contains($q->sql, 'payment_allocations')) {
                return;
            }
            $done = true;
            DB::table('payment_allocations')->insert(['customer_payment_id' => $payment->id, 'subscription_event_id' => $event->id, 'subscription_id' => $event->subscription_id, 'subscriber_id' => $event->subscriber_id, 'period_start' => $event->to_period_start, 'period_end' => $event->to_period_end, 'amount' => $amount, 'currency' => 'USD', 'allocated_at' => now(), 'actor_ref' => 'other-process', 'idempotency_key' => $key, 'created_at' => now()]);
        });
    };

    $inject('20.00');
    $won = allocations()->allocatePayment($payment->id, $event->id, '20.00', $key); // same facts ⇒ the row the other process wrote
    expect($won->wasRecentlyCreated)->toBeFalse()->and($won->actor_ref)->toBe('other-process')
        ->and(PaymentAllocation::count())->toBe(1)->and($audits())->toBe(0); // the loser writes no audit: the winner's audit is the only one

    $key = 'race-'.str()->random(6);
    $inject('25.00');
    expect(fn () => allocations()->allocatePayment($payment->id, $event->id, '20.00', $key))->toThrow(PaymentConflictException::class, 'بحقائق مختلفة (#')
        ->and(PaymentAllocation::count())->toBe(1)->and($audits())->toBe(0); // the loser's outer transaction rolled back (here it also carried the simulated row); the real cross-process case is the PostgreSQL test

    // the connection is healthy after the caught violation: a normal allocation still works in the same test
    expect(allocations()->allocatePayment($payment->id, $event->id, '5.00', e1Key())->wasRecentlyCreated)->toBeTrue()->and($audits())->toBe(1);
});
