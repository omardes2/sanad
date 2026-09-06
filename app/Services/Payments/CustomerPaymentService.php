<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Data\Payments\ManualPaymentInput;
use App\Enums\CustomerPaymentEventType;
use App\Enums\PaymentSource;
use App\Exceptions\Payments\PaymentConflictException;
use App\Exceptions\Payments\PaymentRuleException;
use App\Exceptions\Payments\StalePaymentStateException;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentEvent;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Audit\AuditActions;
use App\Support\Payments\FinanceAuthorization;
use App\Support\Payments\MoneyRules;
use App\Support\Rbac\Permission;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Domain recording of customer payments (Phase E1, manual source only). This
 * is NOT a gateway API: no authorize/capture here — a real PaymentGateway
 * contract arrives with the first live integration.
 *
 * recordManual(): one transaction writes the payment row, the created and
 * succeeded lifecycle events, the projection and the audit entry — or nothing.
 * Idempotency is PostgreSQL-safe: the insert runs in a SAVEPOINT (nested
 * DB::transaction), so a unique-key race rolls back only the savepoint and the
 * outer transaction stays usable to re-read the winner and compare facts:
 * identical facts ⇒ the existing payment is returned; different ⇒ conflict.
 *
 * transition(): the generic lifecycle mutation (reserved for gateway flows):
 * lock → verify expected state token → append event → update projection →
 * audit, in one transaction. Never last-writer-wins.
 */
final class CustomerPaymentService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws PaymentRuleException|PaymentConflictException
     */
    public function recordManual(ManualPaymentInput $input): CustomerPayment
    {
        FinanceAuthorization::assertCan(Permission::FinancePaymentsManage);

        $facts = $this->normalise($input);

        return DB::transaction(function () use ($facts, $input): CustomerPayment {
            try {
                return DB::transaction(fn (): CustomerPayment => $this->insert($facts, $input)); // savepoint
            } catch (UniqueConstraintViolationException) {
                // The outer transaction is intact (savepoint rolled back). Find
                // the record that owns the key / reference and compare facts.
                $existing = CustomerPayment::query()->where('idempotency_key', $facts['idempotency_key'])->first();

                if ($existing === null && $facts['gateway_payment_ref'] !== null) {
                    $existing = CustomerPayment::query()->where('gateway', $facts['gateway'])->where('gateway_payment_ref', $facts['gateway_payment_ref'])->first();

                    // Same external reference under a DIFFERENT idempotency key is always a conflict.
                    throw new PaymentConflictException('المرجع الخارجي مسجَّل مسبقًا لدفعة أخرى (مفتاح idempotency مختلف).');
                }

                if ($existing === null) {
                    throw new PaymentConflictException('تعذّر تحديد الدفعة المتعارضة؛ أعد المحاولة.');
                }

                if (! $this->sameFacts($existing, $facts)) {
                    throw new PaymentConflictException("مفتاح idempotency [{$facts['idempotency_key']}] مستخدم لدفعة بحقائق مختلفة (#{$existing->id}). لم يُكتب شيء.");
                }

                return $existing;
            }
        });
    }

    /**
     * Append a lifecycle event and move the projection — only when the caller
     * acted on the current state (expected token), never last-writer-wins.
     *
     * @throws StalePaymentStateException|PaymentRuleException
     */
    public function transition(CustomerPayment $payment, CustomerPaymentEventType $to, string $expectedToken, PaymentSource $source, ?string $reasonCode = null, ?string $evidenceRef = null): CustomerPayment
    {
        FinanceAuthorization::assertCan(Permission::FinancePaymentsManage);

        return DB::transaction(function () use ($payment, $to, $expectedToken, $source, $reasonCode, $evidenceRef): CustomerPayment {
            $locked = CustomerPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if (! hash_equals($locked->stateToken(), $expectedToken)) {
                throw new StalePaymentStateException("حالة الدفعة تغيّرت (المتوقع {$expectedToken}، الحالي {$locked->stateToken()}). لم يُكتب شيء.");
            }

            $allowed = match ($locked->current_status) {
                CustomerPaymentEventType::Created => [CustomerPaymentEventType::Succeeded, CustomerPaymentEventType::Failed],
                CustomerPaymentEventType::Succeeded => [CustomerPaymentEventType::Disputed],
                CustomerPaymentEventType::Disputed => [CustomerPaymentEventType::DisputeResolved],
                default => [],
            };

            if (! in_array($to, $allowed, true)) {
                throw PaymentRuleException::of('lifecycle', "الانتقال من {$locked->current_status->value} إلى {$to->value} غير مسموح.");
            }

            $from = $locked->current_status;
            $event = $this->appendEvent($locked, $to, $source, CarbonImmutable::now(), $reasonCode, $evidenceRef);
            $locked->forceFill(['current_status' => $to->value, 'latest_event_id' => $event->id])->save();

            $this->audit->record(AuditActions::PaymentTransitioned, $locked, [
                'current_status' => ['from' => $from->value, 'to' => $to->value],
            ], ['event_id' => $event->id, 'source' => $source->value, 'reason_code' => $reasonCode, 'subscriber_id' => $locked->subscriber_id]);

            $payment->setRawAttributes($locked->getAttributes(), true);

            return $payment;
        });
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function insert(array $facts, ManualPaymentInput $input): CustomerPayment
    {
        $now = CarbonImmutable::now();

        $payment = CustomerPayment::query()->create($facts + [
            'user_id' => User::query()->whereKey($input->subscriberId)->exists() ? $input->subscriberId : null,
            'current_status' => CustomerPaymentEventType::Created->value,
            'latest_event_id' => null,
        ]);

        $this->appendEvent($payment, CustomerPaymentEventType::Created, PaymentSource::Manual, $now, $facts['reason_code'], $facts['evidence_ref']);
        $succeeded = $this->appendEvent($payment, CustomerPaymentEventType::Succeeded, PaymentSource::Manual, $now, $facts['reason_code'], $facts['evidence_ref']);

        $payment->forceFill(['current_status' => CustomerPaymentEventType::Succeeded->value, 'latest_event_id' => $succeeded->id])->save();

        // Atomic with the rows: an audit failure rolls everything back.
        $this->audit->record(AuditActions::PaymentRecorded, $payment, [
            'current_status' => ['from' => null, 'to' => CustomerPaymentEventType::Succeeded->value],
        ], [
            'subscriber_id' => $payment->subscriber_id,
            'gateway' => $payment->gateway,
            'amount' => (string) $payment->amount,
            'currency' => $payment->currency,
            'gateway_fee' => $payment->gateway_fee_amount === null ? 'UNKNOWN' : (string) $payment->gateway_fee_amount,
            'received_at' => $payment->received_at->toIso8601String(),
            'idempotency_key' => $payment->idempotency_key,
            'succeeded_event_id' => $succeeded->id,
        ]);

        return $payment;
    }

    private function appendEvent(CustomerPayment $payment, CustomerPaymentEventType $type, PaymentSource $source, CarbonImmutable $at, ?string $reasonCode, ?string $evidenceRef): CustomerPaymentEvent
    {
        return CustomerPaymentEvent::query()->create([
            'customer_payment_id' => $payment->id,
            'event_type' => $type->value,
            'occurred_at' => $at,
            'source' => $source->value,
            'actor_ref' => FinanceAuthorization::actorRef(),
            'reason_code' => $reasonCode,
            'evidence_ref' => $evidenceRef,
            'metadata' => null,
            'created_at' => $at,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalise(ManualPaymentInput $input): array
    {
        $amount = MoneyRules::positiveAmount($input->amount, 'amount');
        $currency = MoneyRules::currency($input->currency, 'currency');
        MoneyRules::notInFuture($input->receivedAt, 'received_at');

        $key = trim($input->idempotencyKey);

        if ($key === '' || mb_strlen($key) > 191) {
            throw PaymentRuleException::of('idempotency_key', 'مفتاح idempotency إلزامي (حتى 191 حرفًا).');
        }

        $fee = null;
        $feeCurrency = null;

        if ($input->gatewayFeeAmount !== null && trim($input->gatewayFeeAmount) !== '') {
            $fee = MoneyRules::nonNegativeAmount($input->gatewayFeeAmount, 'gateway_fee_amount');

            if ($input->feeCurrency === null || trim($input->feeCurrency) === '') {
                throw PaymentRuleException::of('fee_currency', 'عملة الرسوم إلزامية عند إدخال رسوم البوابة.');
            }

            $feeCurrency = MoneyRules::currency($input->feeCurrency, 'fee_currency');
            MoneyRules::sameCurrency($currency, $feeCurrency, 'fee_currency');
        } elseif ($input->feeCurrency !== null && trim($input->feeCurrency) !== '') {
            throw PaymentRuleException::of('fee_currency', 'عملة الرسوم بلا مبلغ رسوم غير مقبولة (الرسوم غير المعروفة تُترك فارغة).');
        }

        return [
            'subscriber_id' => $input->subscriberId,
            'gateway' => CustomerPayment::GATEWAY_MANUAL,
            'gateway_payment_ref' => MoneyRules::boundedRef($input->gatewayPaymentRef, 191, 'gateway_payment_ref'),
            'idempotency_key' => $key,
            'amount' => MoneyRules::format($amount),
            'currency' => $currency,
            'gateway_fee_amount' => $fee === null ? null : MoneyRules::format($fee),
            'fee_currency' => $feeCurrency,
            'received_at' => $input->receivedAt,
            'reference' => MoneyRules::boundedRef($input->reference, 64, 'reference'),
            'reason_code' => MoneyRules::boundedRef($input->reasonCode, 32, 'reason_code'),
            'evidence_ref' => MoneyRules::boundedRef($input->evidenceRef, 191, 'evidence_ref'),
            'recorded_by_ref' => FinanceAuthorization::actorRef(),
        ];
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function sameFacts(CustomerPayment $existing, array $facts): bool
    {
        return $existing->subscriber_id === $facts['subscriber_id']
            && $existing->gateway === $facts['gateway']
            && $existing->gateway_payment_ref === $facts['gateway_payment_ref']
            && (string) $existing->amount === $facts['amount']
            && $existing->currency === $facts['currency']
            && ($existing->gateway_fee_amount === null ? null : (string) $existing->gateway_fee_amount) === $facts['gateway_fee_amount']
            && $existing->fee_currency === $facts['fee_currency']
            && $existing->received_at->format(CustomerPayment::TIMESTAMP_FORMAT) === $facts['received_at']->format(CustomerPayment::TIMESTAMP_FORMAT);
    }
}
