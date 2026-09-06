<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Data\Payments\RefundInput;
use App\Enums\CustomerPaymentEventType;
use App\Exceptions\Payments\PaymentConflictException;
use App\Exceptions\Payments\PaymentRuleException;
use App\Models\CustomerPayment;
use App\Models\CustomerRefund;
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
 * Refunds (Phase E1): only against a payment that actually SUCCEEDED (event-
 * based), same currency, refunded_at ≥ received_at and not in the future,
 * Σ refunds ≤ the payment amount — checked under the payment row lock, so
 * concurrent refunds either fully succeed or are fully refused (no clipping).
 * Idempotent via a savepoint (PostgreSQL-safe): same key + same facts ⇒ the
 * existing refund; different facts ⇒ conflict. Never updated or deleted.
 */
final class RefundService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws PaymentRuleException|PaymentConflictException
     */
    public function record(RefundInput $input): CustomerRefund
    {
        FinanceAuthorization::assertCan(Permission::FinancePaymentsManage);

        $amount = MoneyRules::positiveAmount($input->amount, 'amount');
        MoneyRules::notInFuture($input->refundedAt, 'refunded_at');
        $key = trim($input->idempotencyKey);

        if ($key === '' || mb_strlen($key) > 191) {
            throw PaymentRuleException::of('idempotency_key', 'مفتاح idempotency إلزامي (حتى 191 حرفًا).');
        }

        $reason = MoneyRules::boundedRef($input->reasonCode, 32, 'reason_code');

        if ($reason === null) {
            throw PaymentRuleException::of('reason_code', 'رمز السبب إلزامي للاسترداد.');
        }

        return DB::transaction(function () use ($input, $amount, $key, $reason): CustomerRefund {
            // Serialise every refund of this payment on the payment row.
            $payment = CustomerPayment::query()->whereKey($input->customerPaymentId)->lockForUpdate()->first();

            if ($payment === null) {
                throw PaymentRuleException::of('payment', 'الدفعة غير موجودة.');
            }

            self::assertSucceeded($payment, 'refund');

            if ($input->refundedAt->lessThan($payment->received_at)) {
                throw PaymentRuleException::of('refunded_at', 'تاريخ الاسترداد لا يمكن أن يسبق تاريخ استلام الدفعة.');
            }

            $already = DecimalMath::intFromDb(CustomerRefund::query()->where('customer_payment_id', $payment->id)->where('idempotency_key', '!=', $key)->selectRaw('COALESCE(SUM(ROUND(amount * 100)), 0) AS s')->value('s'));
            $limit = DecimalMath::toScaled((string) $payment->amount, MoneyRules::SCALE);

            if ($already + $amount > $limit) {
                throw PaymentRuleException::of('refund_limit', 'مجموع الاستردادات ('.MoneyRules::format($already + $amount).') يتجاوز مبلغ الدفعة ('.MoneyRules::format($limit).'). لم يُكتب شيء.');
            }

            $facts = [
                'customer_payment_id' => $payment->id,
                'gateway' => $payment->gateway,
                'gateway_refund_ref' => MoneyRules::boundedRef($input->gatewayRefundRef, 191, 'gateway_refund_ref'),
                'idempotency_key' => $key,
                'amount' => MoneyRules::format($amount),
                'currency' => $payment->currency,
                'refunded_at' => $input->refundedAt,
                'reason_code' => $reason,
                'evidence_ref' => MoneyRules::boundedRef($input->evidenceRef, 191, 'evidence_ref'),
                'recorded_by_ref' => FinanceAuthorization::actorRef(),
                'created_at' => CarbonImmutable::now(),
            ];

            try {
                return DB::transaction(function () use ($facts, $payment): CustomerRefund { // savepoint
                    $refund = CustomerRefund::query()->create($facts);

                    $this->audit->record(AuditActions::PaymentRefunded, $payment, [
                        'refund' => ['from' => null, 'to' => ['id' => $refund->id, 'amount' => (string) $refund->amount, 'currency' => $refund->currency]],
                    ], ['subscriber_id' => $payment->subscriber_id, 'refunded_at' => $refund->refunded_at->toIso8601String(), 'reason_code' => $refund->reason_code, 'idempotency_key' => $refund->idempotency_key]);

                    return $refund;
                });
            } catch (UniqueConstraintViolationException) {
                $existing = CustomerRefund::query()->where('idempotency_key', $key)->first();

                if ($existing === null) {
                    throw new PaymentConflictException('المرجع الخارجي للاسترداد مسجَّل مسبقًا لاسترداد آخر.');
                }

                $same = $existing->customer_payment_id === $payment->id
                    && (string) $existing->amount === $facts['amount']
                    && $existing->refunded_at->format(CustomerPayment::TIMESTAMP_FORMAT) === $input->refundedAt->format(CustomerPayment::TIMESTAMP_FORMAT)
                    && $existing->reason_code === $reason;

                if (! $same) {
                    throw new PaymentConflictException("مفتاح idempotency [{$key}] مستخدم لاسترداد بحقائق مختلفة (#{$existing->id}). لم يُكتب شيء.");
                }

                return $existing;
            }
        });
    }

    /**
     * Only a payment that actually succeeded (event-based, plus a projection
     * that is not failed) may be refunded or allocated.
     *
     * @throws PaymentRuleException
     */
    public static function assertSucceeded(CustomerPayment $payment, string $operation): void
    {
        if (! $payment->hasSucceeded() || $payment->current_status === CustomerPaymentEventType::Failed) {
            throw PaymentRuleException::of('lifecycle', "العملية [{$operation}] مسموحة فقط لدفعة نجحت فعليًا (الحالة الحالية: {$payment->current_status->value}).");
        }
    }
}
