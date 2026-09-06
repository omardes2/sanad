<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Exceptions\Payments\PaymentConflictException;
use App\Exceptions\Payments\PaymentRuleException;
use App\Models\CustomerPayment;
use App\Models\CustomerRefund;
use App\Models\PaymentAllocation;
use App\Models\RefundAllocation;
use App\Models\SubscriptionEvent;
use App\Services\Audit\AuditLogger;
use App\Support\Audit\AuditActions;
use App\Support\Billing\DecimalMath;
use App\Support\Payments\FinanceAuthorization;
use App\Support\Payments\MoneyRules;
use App\Support\Rbac\Permission;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Attribution of collected cash (Phase E1) — never revenue, never recognition.
 *
 *  - allocatePayment(): a succeeded payment's cash is attributed to the
 *    service period of ONE subscription_events row (its to_period_* snapshot;
 *    no hand-typed dates), same subscriber, same currency, Σ ≤ the payment
 *    amount under the payment row lock. Append-only.
 *  - allocateRefund(): a refund is attributed to a payment allocation it
 *    reverses: Σ per refund ≤ refund amount AND Σ per allocation ≤ allocation
 *    amount, same currency, under the payment row lock. The original
 *    allocation is never modified.
 * Every operation either fully succeeds or is fully refused — no clipping.
 *
 * Idempotency (E5.2a, durable): every NEW row requires a caller-owned opaque
 * key (the column is nullable only for rows written before E5.2a). Same key +
 * same canonical facts ⇒ the existing row, no new row, no new audit; same
 * key + any different fact ⇒ PaymentConflictException, nothing written. The
 * database unique index is the authority: the insert runs in a savepoint,
 * a unique violation is caught, the existing row is fetched by key and its
 * facts compared — the same pattern as RefundService. The caps, locks,
 * subscriber / period / currency rules and amount semantics are unchanged;
 * the key is an additional layer only.
 */
final class AllocationService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws PaymentRuleException|PaymentConflictException
     */
    public function allocatePayment(int $paymentId, int $subscriptionEventId, string $amount, string $idempotencyKey, ?string $reasonCode = null): PaymentAllocation
    {
        FinanceAuthorization::assertCan(Permission::FinancePaymentsManage);
        $scaled = MoneyRules::positiveAmount($amount, 'amount');
        $key = MoneyRules::idempotencyKey($idempotencyKey);
        $reason = MoneyRules::boundedRef($reasonCode, 32, 'reason_code');

        return DB::transaction(function () use ($paymentId, $subscriptionEventId, $scaled, $key, $reason): PaymentAllocation {
            $payment = CustomerPayment::query()->whereKey($paymentId)->lockForUpdate()->first();

            if ($payment === null) {
                throw PaymentRuleException::of('payment', 'الدفعة غير موجودة.');
            }

            RefundService::assertSucceeded($payment, 'allocate');

            $event = SubscriptionEvent::query()->whereKey($subscriptionEventId)->first();

            if ($event === null) {
                throw PaymentRuleException::of('subscription_event', 'حدث الاشتراك غير موجود.');
            }

            if ($event->subscriber_id !== $payment->subscriber_id) {
                throw PaymentRuleException::of('subscriber_mismatch', 'حدث الاشتراك يخص مشتركًا آخر غير صاحب الدفعة.');
            }

            if ($event->to_period_start === null || $event->to_period_end === null || ! $event->to_period_end->greaterThan($event->to_period_start)) {
                throw PaymentRuleException::of('period', 'حدث الاشتراك لا يحمل فترة خدمة صالحة (بداية ونهاية، النهاية بعد البداية).');
            }

            $now = CarbonImmutable::now();
            $facts = [
                'customer_payment_id' => $payment->id,
                'subscription_event_id' => $event->id,
                'subscription_id' => $event->subscription_id,
                'subscriber_id' => $event->subscriber_id,
                'period_start' => $event->to_period_start,
                'period_end' => $event->to_period_end,
                'amount' => MoneyRules::format($scaled),
                'currency' => $payment->currency,
                'allocated_at' => $now,
                'actor_ref' => FinanceAuthorization::actorRef(),
                'reason_code' => $reason,
                'idempotency_key' => $key,
                'created_at' => $now,
            ];

            // Replay / conflict seen under the payment lock (a committed same-key row); the unique index below is the authority.
            $existing = PaymentAllocation::query()->where('idempotency_key', $key)->first();

            if ($existing !== null) {
                return self::samePaymentAllocation($existing, $facts) ? $existing : throw self::conflict($key, $existing->id);
            }

            // The cap is a bound on OTHER rows of this payment (a same-key row was returned above): unchanged E1 rule.
            $already = DecimalMath::intFromDb(PaymentAllocation::query()->where('customer_payment_id', $payment->id)->selectRaw('COALESCE(SUM(ROUND(amount * 100)), 0) AS s')->value('s'));
            $limit = DecimalMath::toScaled((string) $payment->amount, MoneyRules::SCALE);

            if ($already + $scaled > $limit) {
                throw PaymentRuleException::of('allocation_limit', 'مجموع التخصيصات ('.MoneyRules::format($already + $scaled).') يتجاوز مبلغ الدفعة ('.MoneyRules::format($limit).'). لم يُكتب شيء.');
            }

            try {
                return DB::transaction(function () use ($facts, $payment, $event): PaymentAllocation { // savepoint: row + its audit, or neither
                    $allocation = PaymentAllocation::query()->create($facts);

                    $this->audit->record(AuditActions::PaymentAllocated, $payment, [
                        'allocation' => ['from' => null, 'to' => ['id' => $allocation->id, 'amount' => (string) $allocation->amount, 'currency' => $allocation->currency]],
                    ], [
                        'subscriber_id' => $payment->subscriber_id,
                        'subscription_event_id' => $event->id,
                        'period_start' => $allocation->period_start->toIso8601String(),
                        'period_end' => $allocation->period_end->toIso8601String(),
                        'idempotency_key' => $allocation->idempotency_key,
                    ]);

                    return $allocation;
                });
            } catch (UniqueConstraintViolationException) {
                $existing = PaymentAllocation::query()->where('idempotency_key', $key)->first();

                if ($existing === null || ! self::samePaymentAllocation($existing, $facts)) {
                    throw self::conflict($key, $existing?->id);
                }

                return $existing;
            }
        });
    }

    /**
     * @throws PaymentRuleException|PaymentConflictException
     */
    public function allocateRefund(int $refundId, int $paymentAllocationId, string $amount, string $idempotencyKey, ?string $reasonCode = null): RefundAllocation
    {
        FinanceAuthorization::assertCan(Permission::FinancePaymentsManage);
        $scaled = MoneyRules::positiveAmount($amount, 'amount');
        $key = MoneyRules::idempotencyKey($idempotencyKey);
        $reason = MoneyRules::boundedRef($reasonCode, 32, 'reason_code');

        return DB::transaction(function () use ($refundId, $paymentAllocationId, $scaled, $key, $reason): RefundAllocation {
            $refund = CustomerRefund::query()->whereKey($refundId)->first();

            if ($refund === null) {
                throw PaymentRuleException::of('refund', 'الاسترداد غير موجود.');
            }

            // One lock for everything hanging off this payment.
            $payment = CustomerPayment::query()->whereKey($refund->customer_payment_id)->lockForUpdate()->firstOrFail();
            $allocation = PaymentAllocation::query()->whereKey($paymentAllocationId)->first();

            if ($allocation === null || $allocation->customer_payment_id !== $payment->id) {
                throw PaymentRuleException::of('allocation', 'التخصيص غير موجود أو لا يخص دفعة هذا الاسترداد.');
            }

            MoneyRules::sameCurrency($refund->currency, $allocation->currency, 'currency');

            $now = CarbonImmutable::now();
            $facts = [
                'customer_refund_id' => $refund->id,
                'payment_allocation_id' => $allocation->id,
                'amount' => MoneyRules::format($scaled),
                'currency' => $refund->currency,
                'allocated_at' => $now,
                'actor_ref' => FinanceAuthorization::actorRef(),
                'reason_code' => $reason,
                'idempotency_key' => $key,
                'created_at' => $now,
            ];

            $existing = RefundAllocation::query()->where('idempotency_key', $key)->first();

            if ($existing !== null) {
                return self::sameRefundAllocation($existing, $facts) ? $existing : throw self::conflict($key, $existing->id);
            }

            $onRefund = DecimalMath::intFromDb(RefundAllocation::query()->where('customer_refund_id', $refund->id)->selectRaw('COALESCE(SUM(ROUND(amount * 100)), 0) AS s')->value('s'));
            $onAllocation = DecimalMath::intFromDb(RefundAllocation::query()->where('payment_allocation_id', $allocation->id)->selectRaw('COALESCE(SUM(ROUND(amount * 100)), 0) AS s')->value('s'));
            $refundLimit = DecimalMath::toScaled((string) $refund->amount, MoneyRules::SCALE);
            $allocationLimit = DecimalMath::toScaled((string) $allocation->amount, MoneyRules::SCALE);

            if ($onRefund + $scaled > $refundLimit) {
                throw PaymentRuleException::of('refund_allocation_limit', 'مجموع تخصيصات الاسترداد ('.MoneyRules::format($onRefund + $scaled).') يتجاوز مبلغ الاسترداد ('.MoneyRules::format($refundLimit).'). لم يُكتب شيء.');
            }

            if ($onAllocation + $scaled > $allocationLimit) {
                throw PaymentRuleException::of('allocation_reversal_limit', 'مجموع الاستردادات المنسوبة للتخصيص ('.MoneyRules::format($onAllocation + $scaled).') يتجاوز مبلغ التخصيص ('.MoneyRules::format($allocationLimit).'). لم يُكتب شيء.');
            }

            try {
                return DB::transaction(function () use ($facts, $payment, $refund, $allocation): RefundAllocation { // savepoint: row + its audit, or neither
                    $row = RefundAllocation::query()->create($facts);

                    $this->audit->record(AuditActions::RefundAllocated, $payment, [
                        'refund_allocation' => ['from' => null, 'to' => ['id' => $row->id, 'amount' => (string) $row->amount, 'currency' => $row->currency]],
                    ], ['subscriber_id' => $payment->subscriber_id, 'refund_id' => $refund->id, 'payment_allocation_id' => $allocation->id, 'idempotency_key' => $row->idempotency_key]);

                    return $row;
                });
            } catch (UniqueConstraintViolationException) {
                $existing = RefundAllocation::query()->where('idempotency_key', $key)->first();

                if ($existing === null || ! self::sameRefundAllocation($existing, $facts)) {
                    throw self::conflict($key, $existing?->id);
                }

                return $existing;
            }
        });
    }

    /**
     * Canonical facts of a payment allocation: parent payment, target event and
     * its period snapshot, subscription / subscriber, amount, currency, reason.
     *
     * @param  array<string, mixed>  $facts
     */
    private static function samePaymentAllocation(PaymentAllocation $existing, array $facts): bool
    {
        return $existing->customer_payment_id === $facts['customer_payment_id']
            && $existing->subscription_event_id === $facts['subscription_event_id']
            && (int) $existing->subscription_id === (int) $facts['subscription_id']
            && (int) $existing->subscriber_id === (int) $facts['subscriber_id']
            && $existing->period_start->equalTo($facts['period_start'])
            && $existing->period_end->equalTo($facts['period_end'])
            && (string) $existing->amount === $facts['amount']
            && $existing->currency === $facts['currency']
            && $existing->reason_code === $facts['reason_code'];
    }

    /**
     * Canonical facts of a refund allocation: refund, reversed allocation, amount, currency, reason.
     *
     * @param  array<string, mixed>  $facts
     */
    private static function sameRefundAllocation(RefundAllocation $existing, array $facts): bool
    {
        return $existing->customer_refund_id === $facts['customer_refund_id']
            && $existing->payment_allocation_id === $facts['payment_allocation_id']
            && (string) $existing->amount === $facts['amount']
            && $existing->currency === $facts['currency']
            && $existing->reason_code === $facts['reason_code'];
    }

    private static function conflict(string $key, ?int $existingId): PaymentConflictException
    {
        return new PaymentConflictException("مفتاح idempotency [{$key}] مستخدم لتخصيص بحقائق مختلفة".($existingId === null ? '' : " (#{$existingId})").'. لم يُكتب شيء.');
    }
}
