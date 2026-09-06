<?php

declare(strict_types=1);

use App\Enums\CustomerPaymentEventType;
use App\Enums\PaymentSource;
use App\Exceptions\Payments\ImmutableFinancialRecordException;
use App\Exceptions\Payments\PaymentConflictException;
use App\Exceptions\Payments\PaymentRuleException;
use App\Exceptions\Payments\StalePaymentStateException;
use App\Models\AuditLog;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentEvent;
use App\Services\Audit\AuditLogger;
use App\Services\Payments\CustomerPaymentService;
use App\Support\Audit\AuditActions;
use App\Support\Security\SecretRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Phase E1 — customer payments: identity + immutable facts, the append-only
 * lifecycle (customer_payment_events) with current_status as a projection,
 * mandatory idempotency, fee UNKNOWN semantics, temporal / currency rules,
 * atomic audit, and stale-safe transitions.
 */
it('records a manual payment as created → succeeded in one transaction: two events, projection = latest event, one audit row', function () {
    $subscriber = billingSubscriber();
    $received = CarbonImmutable::parse('2026-09-05 10:15:30.123456', 'UTC');

    $payment = e1Payment($subscriber, ['idempotencyKey' => 'inv-1', 'amount' => '49.90', 'currency' => 'usd', 'receivedAt' => $received, 'reference' => 'BANK-2026-09-05', 'reasonCode' => 'bank_transfer', 'evidenceRef' => 'ticket:441']);

    $events = CustomerPaymentEvent::query()->where('customer_payment_id', $payment->id)->orderBy('id')->get();

    expect($payment->wasRecentlyCreated)->toBeTrue()
        ->and($payment->gateway)->toBe('manual')
        ->and($payment->gateway_payment_ref)->toBeNull() // never invented
        ->and((string) $payment->amount)->toBe('49.90')
        ->and($payment->currency)->toBe('USD')
        ->and($payment->user_id)->toBe($subscriber->id)
        ->and($payment->subscriber_id)->toBe($subscriber->id)
        ->and($payment->received_at->format('Y-m-d H:i:s.u'))->toBe('2026-09-05 10:15:30.123456')
        ->and($payment->recorded_by_ref)->toBe('console')
        ->and($events)->toHaveCount(2)
        ->and($events[0]->event_type)->toBe(CustomerPaymentEventType::Created)
        ->and($events[1]->event_type)->toBe(CustomerPaymentEventType::Succeeded)
        ->and($events[1]->source)->toBe(PaymentSource::Manual)
        ->and($events[1]->actor_ref)->toBe('console')
        ->and($payment->current_status)->toBe(CustomerPaymentEventType::Succeeded)
        ->and($payment->latest_event_id)->toBe($events[1]->id) // projection = latest event
        ->and($payment->stateToken())->toBe('e:'.$events[1]->id)
        ->and($payment->hasSucceeded())->toBeTrue();

    $audit = AuditLog::where('action', AuditActions::PaymentRecorded)->get();
    expect($audit)->toHaveCount(1)
        ->and($audit[0]->subject_id)->toBe($payment->id)
        ->and($audit[0]->metadata['changes']['current_status'])->toBe(['from' => null, 'to' => 'succeeded'])
        ->and($audit[0]->metadata['context']['amount'])->toBe('49.90')
        ->and($audit[0]->metadata['context']['gateway_fee'])->toBe('UNKNOWN')
        ->and($audit[0]->metadata['context'])->not->toHaveKey('email')
        ->and(json_encode($audit[0]->metadata))->not->toContain($subscriber->email); // no PII
});

it('is idempotent: the same key with the same facts returns the same payment and writes nothing new; different facts are a conflict', function () {
    $subscriber = billingSubscriber();
    $received = CarbonImmutable::parse('2026-09-05 10:00:00', 'UTC');
    $first = e1Payment($subscriber, ['idempotencyKey' => 'dup-1', 'receivedAt' => $received]);

    $again = e1Payment($subscriber, ['idempotencyKey' => 'dup-1', 'receivedAt' => $received]);

    expect($again->id)->toBe($first->id)
        ->and($again->wasRecentlyCreated)->toBeFalse()
        ->and(CustomerPayment::count())->toBe(1)
        ->and(CustomerPaymentEvent::count())->toBe(2)
        ->and(AuditLog::where('action', AuditActions::PaymentRecorded)->count())->toBe(1);

    expect(fn () => e1Payment($subscriber, ['idempotencyKey' => 'dup-1', 'amount' => '100.01', 'receivedAt' => $received]))
        ->toThrow(PaymentConflictException::class);
    expect(fn () => e1Payment($subscriber, ['idempotencyKey' => 'dup-1', 'receivedAt' => $received->addSecond()]))
        ->toThrow(PaymentConflictException::class);
    expect(fn () => e1Payment(billingSubscriber(), ['idempotencyKey' => 'dup-1', 'receivedAt' => $received]))
        ->toThrow(PaymentConflictException::class);

    expect(CustomerPayment::count())->toBe(1)->and(CustomerPaymentEvent::count())->toBe(2);
});

it('keeps (gateway, gateway_payment_ref) unique: the same external reference under another key is a conflict, and a missing reference stays NULL', function () {
    $subscriber = billingSubscriber();
    e1Payment($subscriber, ['idempotencyKey' => 'k1', 'gatewayPaymentRef' => 'EXT-777']);

    expect(fn () => e1Payment($subscriber, ['idempotencyKey' => 'k2', 'gatewayPaymentRef' => 'EXT-777']))->toThrow(PaymentConflictException::class);

    // Two manual payments without an external reference are both fine (NULL ≠ NULL).
    e1Payment($subscriber, ['idempotencyKey' => 'k3']);
    e1Payment($subscriber, ['idempotencyKey' => 'k4', 'gatewayPaymentRef' => '   ']);

    expect(CustomerPayment::count())->toBe(3)
        ->and(CustomerPayment::query()->whereNull('gateway_payment_ref')->count())->toBe(2);
});

it('enforces the money and time rules: amount > 0, ISO currency, no future received_at, fee currency = payment currency, fee without currency (and vice versa) refused', function () {
    $subscriber = billingSubscriber();

    $rule = function (array $overrides): string {
        try {
            e1Payment(billingSubscriber(), $overrides);
        } catch (PaymentRuleException $e) {
            return $e->rule;
        }

        return 'none';
    };

    expect($rule(['amount' => '0.00']))->toBe('amount')
        ->and($rule(['amount' => '-5']))->toBe('amount')
        ->and($rule(['amount' => '1.999']))->toBe('amount')
        ->and($rule(['amount' => 'abc']))->toBe('amount')
        ->and($rule(['currency' => 'US']))->toBe('currency')
        ->and($rule(['currency' => 'dollars']))->toBe('currency')
        ->and($rule(['receivedAt' => CarbonImmutable::now('UTC')->addHour()]))->toBe('received_at')
        ->and($rule(['gatewayFeeAmount' => '1.00', 'feeCurrency' => 'EUR']))->toBe('fee_currency')
        ->and($rule(['gatewayFeeAmount' => '1.00']))->toBe('fee_currency')
        ->and($rule(['feeCurrency' => 'USD']))->toBe('fee_currency')
        ->and($rule(['gatewayFeeAmount' => '-1.00', 'feeCurrency' => 'USD']))->toBe('gateway_fee_amount')
        ->and($rule(['idempotencyKey' => '   ']))->toBe('idempotency_key')
        ->and($rule(['reference' => str_repeat('x', 65)]))->toBe('reference')
        ->and($rule(['reference' => "free text\nwith newline"]))->toBe('reference')
        ->and($rule(['reasonCode' => 'a reason that is far too long for a code']))->toBe('reason_code')
        ->and($rule(['evidenceRef' => '<script>']))->toBe('evidence_ref')
        ->and(CustomerPayment::count())->toBe(0)
        ->and(AuditLog::count())->toBe(0);

    // A small clock skew is tolerated; a fee of zero is a KNOWN fee.
    $ok = e1Payment($subscriber, ['receivedAt' => CarbonImmutable::now('UTC')->addSeconds(30), 'gatewayFeeAmount' => '0', 'feeCurrency' => 'usd']);
    expect($ok->feeIsKnown())->toBeTrue()->and((string) $ok->gateway_fee_amount)->toBe('0.00')->and($ok->fee_currency)->toBe('USD');
});

it('treats a NULL gateway fee as FEES UNKNOWN, never zero', function () {
    $unknown = e1Payment(billingSubscriber());
    $known = e1Payment(billingSubscriber(), ['gatewayFeeAmount' => '2.50', 'feeCurrency' => 'USD']);

    expect($unknown->gateway_fee_amount)->toBeNull()
        ->and($unknown->feeIsKnown())->toBeFalse()
        ->and($known->feeIsKnown())->toBeTrue()
        ->and((string) $known->gateway_fee_amount)->toBe('2.50')
        ->and(AuditLog::where('subject_id', $unknown->id)->first()->metadata['context']['gateway_fee'])->toBe('UNKNOWN')
        ->and(AuditLog::where('subject_id', $known->id)->first()->metadata['context']['gateway_fee'])->toBe('2.50');
});

it('never rewrites immutable facts, never deletes a payment, and never updates or deletes a lifecycle event', function () {
    $payment = e1Payment(billingSubscriber());
    $event = $payment->events()->orderByDesc('id')->first();

    foreach (['amount' => '1.00', 'currency' => 'EUR', 'received_at' => CarbonImmutable::now(), 'idempotency_key' => 'other', 'gateway_payment_ref' => 'x', 'subscriber_id' => 999] as $attribute => $value) {
        expect(fn () => $payment->fresh()->forceFill([$attribute => $value])->save())->toThrow(ImmutableFinancialRecordException::class);
    }

    expect(fn () => $payment->delete())->toThrow(ImmutableFinancialRecordException::class)
        ->and(fn () => $event->forceFill(['event_type' => 'failed'])->save())->toThrow(ImmutableFinancialRecordException::class)
        ->and(fn () => $event->delete())->toThrow(ImmutableFinancialRecordException::class)
        ->and(CustomerPayment::count())->toBe(1)
        ->and(CustomerPaymentEvent::count())->toBe(2)
        ->and((string) $payment->fresh()->amount)->toBe('100.00');
});

it('is atomic: when the audit entry cannot be written, no payment and no events survive', function () {
    $subscriber = billingSubscriber();

    app()->instance(AuditLogger::class, new class(app(SecretRedactor::class)) extends AuditLogger
    {
        public function record(string $action, ?Model $subject = null, array $changes = [], array $context = []): AuditLog
        {
            throw new RuntimeException('audit store unavailable');
        }
    });

    expect(fn () => e1Payment($subscriber, ['idempotencyKey' => 'atomic-1']))->toThrow(RuntimeException::class, 'audit store unavailable');

    expect(CustomerPayment::count())->toBe(0)
        ->and(CustomerPaymentEvent::count())->toBe(0)
        ->and(AuditLog::count())->toBe(0);
});

it('moves the projection only through a stale-safe transition: lock → expected token → event → projection → audit; a stale token writes nothing', function () {
    $service = app(CustomerPaymentService::class);
    $payment = e1Payment(billingSubscriber());
    $seen = $payment->stateToken();

    $disputed = $service->transition($payment, CustomerPaymentEventType::Disputed, $seen, PaymentSource::Gateway, 'chargeback', 'case:1');
    $events = CustomerPaymentEvent::query()->where('customer_payment_id', $payment->id)->orderBy('id')->get();

    expect($disputed->current_status)->toBe(CustomerPaymentEventType::Disputed)
        ->and($events)->toHaveCount(3)
        ->and($events[2]->event_type)->toBe(CustomerPaymentEventType::Disputed)
        ->and($events[2]->source)->toBe(PaymentSource::Gateway)
        ->and($disputed->latest_event_id)->toBe($events[2]->id)
        ->and($disputed->stateToken())->not->toBe($seen)
        ->and($disputed->hasSucceeded())->toBeTrue() // the cash WAS collected; history stays
        ->and(AuditLog::where('action', AuditActions::PaymentTransitioned)->count())->toBe(1)
        ->and(AuditLog::where('action', AuditActions::PaymentTransitioned)->first()->metadata['changes']['current_status'])->toBe(['from' => 'succeeded', 'to' => 'disputed']);

    // Someone acting on the OLD view is refused — no silent last-writer-wins.
    expect(fn () => $service->transition($payment->fresh(), CustomerPaymentEventType::DisputeResolved, $seen, PaymentSource::Gateway))
        ->toThrow(StalePaymentStateException::class);
    // An impossible transition is refused too.
    expect(fn () => $service->transition($payment->fresh(), CustomerPaymentEventType::Succeeded, $disputed->stateToken(), PaymentSource::Gateway))
        ->toThrow(PaymentRuleException::class);

    expect(CustomerPaymentEvent::query()->where('customer_payment_id', $payment->id)->count())->toBe(3)
        ->and(AuditLog::where('action', AuditActions::PaymentTransitioned)->count())->toBe(1)
        ->and($payment->fresh()->current_status)->toBe(CustomerPaymentEventType::Disputed);

    // The fresh token works.
    $resolved = $service->transition($payment->fresh(), CustomerPaymentEventType::DisputeResolved, $disputed->stateToken(), PaymentSource::Gateway);
    expect($resolved->current_status)->toBe(CustomerPaymentEventType::DisputeResolved)
        ->and(CustomerPaymentEvent::query()->where('customer_payment_id', $payment->id)->count())->toBe(4);
});

it('refuses a direct projection write that does not go through the service (PostgreSQL CHECK on the event vocabulary)', function () {
    $payment = e1Payment(billingSubscriber());

    // The model refuses an unknown vocabulary value; a raw insert is refused by the database itself.
    expect(fn () => DB::transaction(fn () => DB::table('customer_payment_events')->insert([
        'customer_payment_id' => $payment->id, 'event_type' => 'refunded', 'occurred_at' => now(), 'source' => 'manual', 'actor_ref' => 'console', 'created_at' => now(),
    ])))->toThrow(QueryException::class, 'customer_payment_events_type_check');
    expect(CustomerPaymentEvent::query()->where('customer_payment_id', $payment->id)->count())->toBe(2);
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'CHECK constraints are PostgreSQL-only');
