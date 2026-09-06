<?php

declare(strict_types=1);

namespace App\Services\Payments;

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
 */
final class AllocationService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws PaymentRuleException
     */
    public function allocatePayment(int $paymentId, int $subscriptionEventId, string $amount, ?string $reasonCode = null): PaymentAllocation
    {
        FinanceAuthorization::assertCan(Permission::FinancePaymentsManage);
        $scaled = MoneyRules::positiveAmount($amount, 'amount');
        $reason = MoneyRules::boundedRef($reasonCode, 32, 'reason_code');

        return DB::transaction(function () use ($paymentId, $subscriptionEventId, $scaled, $reason): PaymentAllocation {
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

            $already = DecimalMath::intFromDb(PaymentAllocation::query()->where('customer_payment_id', $payment->id)->selectRaw('COALESCE(SUM(ROUND(amount * 100)), 0) AS s')->value('s'));
            $limit = DecimalMath::toScaled((string) $payment->amount, MoneyRules::SCALE);

            if ($already + $scaled > $limit) {
                throw PaymentRuleException::of('allocation_limit', 'مجموع التخصيصات ('.MoneyRules::format($already + $scaled).') يتجاوز مبلغ الدفعة ('.MoneyRules::format($limit).'). لم يُكتب شيء.');
            }

            $now = CarbonImmutable::now();
            $allocation = PaymentAllocation::query()->create([
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
                'created_at' => $now,
            ]);

            $this->audit->record(AuditActions::PaymentAllocated, $payment, [
                'allocation' => ['from' => null, 'to' => ['id' => $allocation->id, 'amount' => (string) $allocation->amount, 'currency' => $allocation->currency]],
            ], [
                'subscriber_id' => $payment->subscriber_id,
                'subscription_event_id' => $event->id,
                'period_start' => $allocation->period_start->toIso8601String(),
                'period_end' => $allocation->period_end->toIso8601String(),
            ]);

            return $allocation;
        });
    }

    /**
     * @throws PaymentRuleException
     */
    public function allocateRefund(int $refundId, int $paymentAllocationId, string $amount, ?string $reasonCode = null): RefundAllocation
    {
        FinanceAuthorization::assertCan(Permission::FinancePaymentsManage);
        $scaled = MoneyRules::positiveAmount($amount, 'amount');
        $reason = MoneyRules::boundedRef($reasonCode, 32, 'reason_code');

        return DB::transaction(function () use ($refundId, $paymentAllocationId, $scaled, $reason): RefundAllocation {
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

            $now = CarbonImmutable::now();
            $row = RefundAllocation::query()->create([
                'customer_refund_id' => $refund->id,
                'payment_allocation_id' => $allocation->id,
                'amount' => MoneyRules::format($scaled),
                'currency' => $refund->currency,
                'allocated_at' => $now,
                'actor_ref' => FinanceAuthorization::actorRef(),
                'reason_code' => $reason,
                'created_at' => $now,
            ]);

            $this->audit->record(AuditActions::RefundAllocated, $payment, [
                'refund_allocation' => ['from' => null, 'to' => ['id' => $row->id, 'amount' => (string) $row->amount, 'currency' => $row->currency]],
            ], ['subscriber_id' => $payment->subscriber_id, 'refund_id' => $refund->id, 'payment_allocation_id' => $allocation->id]);

            return $row;
        });
    }
}
