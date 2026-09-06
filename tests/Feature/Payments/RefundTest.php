<?php

declare(strict_types=1);

use App\Enums\CustomerPaymentEventType;
use App\Enums\PaymentSource;
use App\Exceptions\Payments\ImmutableFinancialRecordException;
use App\Exceptions\Payments\PaymentConflictException;
use App\Exceptions\Payments\PaymentRuleException;
use App\Models\AuditLog;
use App\Models\CustomerPayment;
use App\Models\CustomerRefund;
use App\Services\Audit\AuditLogger;
use App\Services\Payments\CashCollectedQuery;
use App\Services\Payments\CustomerPaymentService;
use App\Support\Audit\AuditActions;
use App\Support\Security\SecretRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Phase E1 — refunds: only against a payment that actually succeeded, same
 * currency, refunded_at ≥ received_at and not in the future, Σ ≤ the payment
 * amount (fully accepted or fully refused — never clipped), idempotent,
 * append-only, atomic with the audit entry.
 */
function e1PendingPayment(): CustomerPayment
{
    // What a gateway-created payment looks like before capture: identity row,
    // `created` event only — no `succeeded` event, so no cash was collected.
    $payment = CustomerPayment::query()->create([
        'subscriber_id' => billingSubscriber()->id, 'gateway' => 'manual', 'idempotency_key' => 'pending-'.str()->random(6), 'amount' => '100.00', 'currency' => 'USD',
        'received_at' => CarbonImmutable::now('UTC')->subHour(), 'current_status' => CustomerPaymentEventType::Created->value, 'recorded_by_ref' => 'console',
    ]);
    $event = $payment->events()->create(['event_type' => 'created', 'occurred_at' => now(), 'source' => 'gateway', 'actor_ref' => 'system', 'created_at' => now()]);
    $payment->forceFill(['latest_event_id' => $event->id])->save();

    return $payment;
}

it('records a partial refund against a succeeded payment with its own audit row, then refuses any refund that would exceed the payment (no clipping)', function () {
    $payment = e1Payment(billingSubscriber(), ['amount' => '100.00']);

    $r1 = e1Refund($payment, ['amount' => '60.00', 'reasonCode' => 'goodwill']);
    $r2 = e1Refund($payment, ['amount' => '40.00']);

    expect($r1->currency)->toBe('USD')->and($r1->gateway)->toBe('manual')->and($r1->gateway_refund_ref)->toBeNull()
        ->and((string) $r1->amount)->toBe('60.00')->and($r1->reason_code)->toBe('goodwill')->and($r1->recorded_by_ref)->toBe('console')
        ->and(CustomerRefund::count())->toBe(2)
        ->and(AuditLog::where('action', AuditActions::PaymentRefunded)->count())->toBe(2);

    // The payment is fully refunded: even one cent more is refused in full.
    expect(fn () => e1Refund($payment, ['amount' => '0.01']))->toThrow(PaymentRuleException::class, 'يتجاوز مبلغ الدفعة');
    expect(CustomerRefund::count())->toBe(2)->and(AuditLog::where('action', AuditActions::PaymentRefunded)->count())->toBe(2);

    // Another payment: 70 then 40 → the second is refused entirely, not reduced to 30.
    $other = e1Payment(billingSubscriber(), ['amount' => '100.00']);
    e1Refund($other, ['amount' => '70.00']);
    expect(fn () => e1Refund($other, ['amount' => '40.00']))->toThrow(PaymentRuleException::class);
    expect(CustomerRefund::query()->where('customer_payment_id', $other->id)->sum('amount'))->toEqual(70);
    expect((string) $other->fresh()->amount)->toBe('100.00'); // the payment fact never changes
});

it('is idempotent per key and conflicts on different facts', function () {
    $at = CarbonImmutable::parse('2026-09-06 08:00:00', 'UTC');
    $payment = e1Payment(billingSubscriber(), ['receivedAt' => $at->subDay()]);

    $first = e1Refund($payment, ['idempotencyKey' => 'rf-1', 'amount' => '10.00', 'refundedAt' => $at]);
    $again = e1Refund($payment, ['idempotencyKey' => 'rf-1', 'amount' => '10.00', 'refundedAt' => $at]);

    expect($again->id)->toBe($first->id)->and($again->wasRecentlyCreated)->toBeFalse()
        ->and(CustomerRefund::count())->toBe(1)
        ->and(AuditLog::where('action', AuditActions::PaymentRefunded)->count())->toBe(1);

    expect(fn () => e1Refund($payment, ['idempotencyKey' => 'rf-1', 'amount' => '11.00', 'refundedAt' => $at]))->toThrow(PaymentConflictException::class);
    expect(fn () => e1Refund(e1Payment(billingSubscriber(), ['receivedAt' => $at->subDay()]), ['idempotencyKey' => 'rf-1', 'amount' => '10.00', 'refundedAt' => $at]))->toThrow(PaymentConflictException::class);
    expect(CustomerRefund::count())->toBe(1);
});

it('verifies the lifecycle, not just the row: a payment without a succeeded event, or projected as failed, cannot be refunded', function () {
    $pending = e1PendingPayment();

    expect(fn () => e1Refund($pending))->toThrow(PaymentRuleException::class, 'نجحت فعليًا');

    $failed = e1PendingPayment();
    $ev = $failed->events()->create(['event_type' => 'failed', 'occurred_at' => now(), 'source' => 'gateway', 'actor_ref' => 'system', 'created_at' => now()]);
    $failed->forceFill(['current_status' => 'failed', 'latest_event_id' => $ev->id])->save();

    expect(fn () => e1Refund($failed))->toThrow(PaymentRuleException::class)
        ->and(CustomerRefund::count())->toBe(0);
});

it('applies the temporal and reason rules: refunded_at ≥ received_at, not in the future, amount > 0, reason_code mandatory and bounded', function () {
    $received = CarbonImmutable::parse('2026-09-05 12:00:00', 'UTC');
    $payment = e1Payment(billingSubscriber(), ['receivedAt' => $received]);

    $rule = function (array $overrides) use ($payment): string {
        try {
            e1Refund($payment, $overrides);
        } catch (PaymentRuleException $e) {
            return $e->rule;
        }

        return 'none';
    };

    expect($rule(['refundedAt' => $received->subSecond()]))->toBe('refunded_at')
        ->and($rule(['refundedAt' => CarbonImmutable::now('UTC')->addDay()]))->toBe('refunded_at')
        ->and($rule(['amount' => '0']))->toBe('amount')
        ->and($rule(['amount' => '-1']))->toBe('amount')
        ->and($rule(['reasonCode' => '']))->toBe('reason_code')
        ->and($rule(['reasonCode' => 'this reason code is definitely too long']))->toBe('reason_code')
        ->and($rule(['evidenceRef' => "x\ny"]))->toBe('evidence_ref')
        ->and($rule(['idempotencyKey' => ' ']))->toBe('idempotency_key')
        ->and(CustomerRefund::count())->toBe(0);

    // Exactly at received_at is allowed; the refund carries the payment's currency (no input, no mixing).
    $ok = e1Refund($payment, ['refundedAt' => $received]);
    expect($ok->currency)->toBe($payment->currency)->and($ok->refunded_at->equalTo($received))->toBeTrue();
});

it('is append-only and atomic with its audit entry', function () {
    $payment = e1Payment(billingSubscriber());
    $refund = e1Refund($payment);

    expect(fn () => $refund->forceFill(['amount' => '1.00'])->save())->toThrow(ImmutableFinancialRecordException::class)
        ->and(fn () => $refund->delete())->toThrow(ImmutableFinancialRecordException::class)
        ->and(CustomerRefund::count())->toBe(1);

    app()->instance(AuditLogger::class, new class(app(SecretRedactor::class)) extends AuditLogger
    {
        public function record(string $action, ?Model $subject = null, array $changes = [], array $context = []): AuditLog
        {
            throw new RuntimeException('audit store unavailable');
        }
    });

    expect(fn () => e1Refund($payment, ['amount' => '5.00']))->toThrow(RuntimeException::class);
    expect(CustomerRefund::count())->toBe(1)->and(AuditLog::where('action', AuditActions::PaymentRefunded)->count())->toBe(1);
});

it('separates historical success from current eligibility: a disputed payment keeps its collected cash but accepts no new refund until the lifecycle allows it', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));
    $payment = e1Payment(billingSubscriber(), ['amount' => '100.00', 'receivedAt' => CarbonImmutable::parse('2026-08-10', 'UTC')]);
    $cash = fn () => app(CashCollectedQuery::class)->summarise(CarbonImmutable::parse('2026-08-01', 'UTC'), CarbonImmutable::parse('2026-09-01', 'UTC'))['USD']->grossCashCollected;
    $service = app(CustomerPaymentService::class);

    e1Refund($payment, ['amount' => '10.00']); // allowed while succeeded
    $service->transition($payment, CustomerPaymentEventType::Disputed, $payment->stateToken(), PaymentSource::Gateway, 'chargeback');

    expect($payment->fresh()->hasSucceeded())->toBeTrue() // history intact
        ->and($cash())->toBe('100.00') // the original 100 never disappears
        ->and(fn () => e1Refund($payment->fresh(), ['amount' => '10.00']))->toThrow(PaymentRuleException::class, 'succeeded الآن')
        ->and(CustomerRefund::count())->toBe(1);

    // dispute_resolved is a distinct state: still not `succeeded`, still no new refund in E1.
    $service->transition($payment->fresh(), CustomerPaymentEventType::DisputeResolved, $payment->fresh()->stateToken(), PaymentSource::Gateway);
    expect(fn () => e1Refund($payment->fresh(), ['amount' => '10.00']))->toThrow(PaymentRuleException::class)
        ->and($cash())->toBe('100.00')
        ->and(CustomerRefund::count())->toBe(1);
});
