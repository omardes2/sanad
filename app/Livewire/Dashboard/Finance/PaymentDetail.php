<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Finance;

use App\Data\Payments\RefundInput;
use App\Enums\CustomerPaymentEventType;
use App\Enums\FxSubjectType;
use App\Enums\PaymentSource;
use App\Livewire\Dashboard\Finance\Concerns\HandlesPaymentActions;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentEvent;
use App\Models\CustomerRefund;
use App\Models\PaymentAllocation;
use App\Models\SubscriptionEvent;
use App\Services\Fx\ReportingCurrencyService;
use App\Services\Fx\ReportingView;
use App\Services\Payments\AllocationService;
use App\Services\Payments\CustomerPaymentService;
use App\Services\Payments\PaymentLedgerView;
use App\Services\Payments\RefundService;
use App\Support\Payments\MoneyRules;
use App\Support\Rbac\Permission;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Payment detail (Phase E5.2a) — `finance.payments.manage`. Facts only, ids
 * only: the payment, its gateway fee (known / FEES UNKNOWN), its reporting
 * conversion status, the event trail, refunds, allocations, and the
 * remaining refundable / allocatable amounts from the same sums the services
 * cap against. Actions call the E1 services only: dispute / resolve dispute
 * (the existing lifecycle transitions, with the token this page rendered
 * as a hidden field), refund and allocate (one attempt key per attempt,
 * rotated only after success). Stale ⇒ "State changed", record and token
 * refreshed, never re-run automatically. Buttons appear only when the
 * transition is legal; the service stays the authority.
 */
#[Title('تفاصيل الدفعة | سَنَد')]
#[Layout('components.layouts.dashboard')]
class PaymentDetail extends Component
{
    use HandlesPaymentActions;

    public int $paymentId;

    /** The state token this page rendered with — sent back as a hidden field with every lifecycle action. */
    public string $paymentToken = '';

    public ?string $notice = null;

    /** Which confirmation panel is open: dispute | resolve | refund | allocate | null */
    public ?string $confirming = null;

    public string $transitionReason = '';

    public string $transitionEvidence = '';

    // refund attempt
    public string $refundKey = '';

    public string $refundAmount = '';

    public string $refundedAt = '';

    public string $refundReasonCode = '';

    public string $refundGatewayRef = '';

    public string $refundEvidenceRef = '';

    // allocation attempt
    public string $allocationKey = '';

    public string $allocEventId = '';

    public string $allocAmount = '';

    public string $allocReasonCode = '';

    public function mount(CustomerPayment $payment): void
    {
        $this->authorizeManage();
        $this->paymentId = $payment->id;
        $this->paymentToken = $payment->stateToken();
        $this->refundKey = self::freshKey();
        $this->allocationKey = self::freshKey();
        $this->refundedAt = CarbonImmutable::now('UTC')->format(self::DATETIME);
    }

    public function openConfirm(string $action): void
    {
        $this->authorizeManage();
        $this->confirming = in_array($action, ['dispute', 'resolve', 'refund', 'allocate'], true) ? $action : null;
    }

    public function closeConfirm(): void
    {
        $this->confirming = null;
    }

    public function dispute(CustomerPaymentService $service): void
    {
        $this->transition($service, CustomerPaymentEventType::Disputed, 'dispute');
    }

    public function resolveDispute(CustomerPaymentService $service): void
    {
        $this->transition($service, CustomerPaymentEventType::DisputeResolved, 'resolve');
    }

    public function recordRefund(RefundService $service): void
    {
        $ok = $this->attempt('refund', $this->refundKey, function () use ($service): void {
            $payment = $this->payment();
            $this->assertRenderedToken($this->paymentToken, $payment->stateToken());

            $refund = $service->record(new RefundInput(
                customerPaymentId: $payment->id,
                idempotencyKey: $this->refundKey,
                amount: $this->refundAmount,
                refundedAt: $this->utc($this->refundedAt, 'تاريخ الاسترداد'),
                reasonCode: $this->refundReasonCode,
                gatewayRefundRef: self::optional($this->refundGatewayRef),
                evidenceRef: self::optional($this->refundEvidenceRef),
            ));

            $this->notice = $refund->wasRecentlyCreated
                ? "سُجِّل الاسترداد #{$refund->id} — {$refund->amount} {$refund->currency}."
                : "الاسترداد #{$refund->id} مسجَّل مسبقًا بنفس المفتاح والحقائق؛ لم يُكتب شيء جديد.";
        });

        if ($ok) {
            $this->reset('refundAmount', 'refundReasonCode', 'refundGatewayRef', 'refundEvidenceRef', 'confirming');
            $this->refundKey = self::freshKey();
            $this->refundedAt = CarbonImmutable::now('UTC')->format(self::DATETIME);
        }
    }

    public function allocatePayment(AllocationService $service): void
    {
        $ok = $this->attempt('allocation', $this->allocationKey, function () use ($service): void {
            $payment = $this->payment();
            $this->assertRenderedToken($this->paymentToken, $payment->stateToken());

            $allocation = $service->allocatePayment($payment->id, $this->positiveInt($this->allocEventId, 'حدث الاشتراك'), $this->allocAmount, self::optional($this->allocReasonCode));

            $this->notice = "خُصِّص #{$allocation->id}: {$allocation->amount} {$allocation->currency} لفترة ".$allocation->period_start->toDateString().' → '.$allocation->period_end->toDateString().' (الاشتراك #'.$allocation->subscription_id.').';
        }, keepClaimOnSuccess: true); // allocations carry no idempotency key in E1: a reused attempt key stays refused

        if ($ok) {
            $this->reset('allocEventId', 'allocAmount', 'allocReasonCode', 'confirming');
            $this->allocationKey = self::freshKey();
        }
    }

    public function render(PaymentLedgerView $ledger, ReportingView $reporting, ReportingCurrencyService $reportingCurrency)
    {
        $this->authorizeManage();
        $user = auth()->user();
        $payment = $this->payment();

        $sums = $ledger->paymentSums([$payment->id]);
        $refundedCents = $sums['refunded'][$payment->id] ?? 0;
        $allocatedCents = $sums['allocated'][$payment->id] ?? 0;
        $amountCents = PaymentLedgerView::cents((string) $payment->amount);
        $line = $reporting->line(FxSubjectType::CustomerPayment, $payment);

        $refunds = CustomerRefund::query()->where('customer_payment_id', $payment->id)->orderBy('id')->get();
        $allocations = PaymentAllocation::query()->where('customer_payment_id', $payment->id)->orderBy('id')->get();
        $reversed = $ledger->allocationReversed($allocations->pluck('id')->all());

        $warnings = [];
        if (! $payment->feeIsKnown()) {
            $warnings[] = 'FEES UNKNOWN · gateway fee not recorded — Net Cash After Gateway Fees NOT AVAILABLE for this payment (never 0)';
        }
        if ($line->status === 'NOT CONVERTED') {
            $warnings[] = 'NOT CONVERTED · no current frozen conversion '.$line->sourceCurrency.' → '.$reportingCurrency->current().'; reporting totals INCOMPLETE / NOT AVAILABLE';
        }
        if ($payment->current_status === CustomerPaymentEventType::Disputed) {
            $warnings[] = 'UNRESOLVED DISPUTE · payment is currently disputed — historical Cash Collected unchanged; period close blocked (UNRESOLVED_DISPUTES)';
        }

        return view('livewire.dashboard.finance.payment-detail', [
            'payment' => $payment,
            'events' => CustomerPaymentEvent::query()->where('customer_payment_id', $payment->id)->orderBy('id')->get(),
            'refunds' => $refunds,
            'allocations' => $allocations,
            'reversedCents' => $reversed,
            'line' => $line,
            'reportingCurrency' => $reportingCurrency->current(),
            'amountCents' => $amountCents,
            'refundedCents' => $refundedCents,
            'allocatedCents' => $allocatedCents,
            'remainingRefundable' => PaymentLedgerView::money(max(0, $amountCents - $refundedCents)),
            'remainingAllocatable' => PaymentLedgerView::money(max(0, $amountCents - $allocatedCents)),
            'canDispute' => $payment->current_status === CustomerPaymentEventType::Succeeded,
            'canResolve' => $payment->current_status === CustomerPaymentEventType::Disputed,
            'canRefund' => $payment->current_status === CustomerPaymentEventType::Succeeded,
            'eligibleEvents' => $this->confirming === 'allocate' || $this->allocEventId !== '' ? $this->eligibleEvents($payment) : collect(),
            'warnings' => $warnings,
            'canAudit' => (bool) $user->can(Permission::AuditView->value),
            'auditUrl' => route('dashboard.audit', ['subject_type' => 'CustomerPayment', 'subject_id' => $payment->id]),
        ]);
    }

    private function transition(CustomerPaymentService $service, CustomerPaymentEventType $to, string $form): void
    {
        $token = $this->paymentToken; // the token this page rendered with — never re-read before the call

        $ok = $this->attempt($form, $form.':'.$token, function () use ($service, $to, $token): void {
            $reason = MoneyRules::boundedRef($this->transitionReason, 32, 'reason_code');

            if ($reason === null) {
                throw new \InvalidArgumentException('رمز السبب إلزامي لهذا الإجراء (حتى 32 حرفًا).');
            }

            $payment = $service->transition($this->payment(), $to, $token, PaymentSource::Manual, $reason, self::optional($this->transitionEvidence));
            $this->paymentToken = $payment->stateToken();
            $this->notice = 'الحالة الآن '.$payment->current_status->value.' (الحدث #'.$payment->latest_event_id.'). النقد المحصَّل تاريخيًا لم يتغير.';
        });

        if ($ok) {
            $this->reset('transitionReason', 'transitionEvidence', 'confirming');
        }
    }

    /** Only subscription events of the payment's subscriber that carry a valid service period — the service checks the same. */
    private function eligibleEvents(CustomerPayment $payment)
    {
        return SubscriptionEvent::query()
            ->where('subscriber_id', $payment->subscriber_id)
            ->whereNotNull('to_period_start')->whereNotNull('to_period_end')->whereColumn('to_period_end', '>', 'to_period_start')
            ->orderByDesc('id')->limit(50)->get();
    }

    private function payment(): CustomerPayment
    {
        return CustomerPayment::query()->findOrFail($this->paymentId);
    }

    protected function refreshRecord(): void
    {
        $this->paymentToken = $this->payment()->stateToken();
    }
}
