<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Finance;

use App\Enums\FxSubjectType;
use App\Livewire\Dashboard\Finance\Concerns\HandlesPaymentActions;
use App\Models\CustomerPayment;
use App\Models\CustomerRefund;
use App\Models\PaymentAllocation;
use App\Models\RefundAllocation;
use App\Services\Fx\ReportingView;
use App\Services\Payments\AllocationService;
use App\Services\Payments\PaymentLedgerView;
use App\Support\Rbac\Permission;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Refund detail (Phase E5.2a) — `finance.payments.manage`. Facts only: the
 * refund, its original payment, its reporting conversion status, its
 * allocation history and the remaining attributable amount from the same
 * sums the service caps against. Allocate Refund calls the E1 service with
 * the refund prefilled, only the payment's own allocations as targets (each
 * with its remaining reversible amount, display only), one attempt key per
 * attempt. Ids only; audit link with audit.view.
 */
#[Title('تفاصيل الاسترداد | سَنَد')]
#[Layout('components.layouts.dashboard')]
class RefundDetail extends Component
{
    use HandlesPaymentActions;

    public int $refundId;

    public string $paymentToken = '';

    public ?string $notice = null;

    public ?string $confirming = null;

    public string $allocationKey = '';

    public string $rallocAllocationId = '';

    public string $rallocAmount = '';

    public string $rallocReasonCode = '';

    public function mount(CustomerRefund $refund): void
    {
        $this->authorizeManage();
        $this->refundId = $refund->id;
        $this->paymentToken = $refund->payment()->firstOrFail()->stateToken();
        $this->allocationKey = self::freshKey();
    }

    public function openConfirm(string $action): void
    {
        $this->authorizeManage();
        $this->confirming = $action === 'allocate' ? 'allocate' : null;
    }

    public function closeConfirm(): void
    {
        $this->confirming = null;
    }

    public function allocateRefund(AllocationService $service): void
    {
        $ok = $this->attempt('refund_allocation', $this->allocationKey, function () use ($service): void {
            $refund = $this->refund();
            $this->assertRenderedToken($this->paymentToken, $refund->payment()->firstOrFail()->stateToken());

            $row = $service->allocateRefund($refund->id, $this->positiveInt($this->rallocAllocationId, 'معرّف التخصيص'), $this->rallocAmount, self::optional($this->rallocReasonCode));
            $this->notice = "نُسب الاسترداد #{$row->customer_refund_id} إلى التخصيص #{$row->payment_allocation_id} بمبلغ {$row->amount} {$row->currency} (سجل #{$row->id}).";
        }, keepClaimOnSuccess: true); // refund allocations carry no idempotency key in E1: a reused attempt key stays refused

        if ($ok) {
            $this->reset('rallocAllocationId', 'rallocAmount', 'rallocReasonCode', 'confirming');
            $this->allocationKey = self::freshKey();
        }
    }

    public function render(PaymentLedgerView $ledger, ReportingView $reporting)
    {
        $this->authorizeManage();
        $user = auth()->user();
        $refund = $this->refund();
        $payment = CustomerPayment::query()->findOrFail($refund->customer_payment_id);

        $allocations = RefundAllocation::query()->where('customer_refund_id', $refund->id)->orderBy('id')->get();
        $targets = PaymentAllocation::query()->where('customer_payment_id', $payment->id)->orderBy('id')->get();
        $reversed = $ledger->allocationReversed($targets->pluck('id')->all());
        $amountCents = PaymentLedgerView::cents((string) $refund->amount);
        $attributedCents = $ledger->refundAllocated([$refund->id])[$refund->id] ?? 0;

        return view('livewire.dashboard.finance.refund-detail', [
            'refund' => $refund,
            'payment' => $payment,
            'line' => $reporting->line(FxSubjectType::CustomerRefund, $refund),
            'allocations' => $allocations,
            'targets' => $targets,
            'reversedCents' => $reversed,
            'attributedCents' => $attributedCents,
            'remainingAttributable' => PaymentLedgerView::money(max(0, $amountCents - $attributedCents)),
            'canAudit' => (bool) $user->can(Permission::AuditView->value),
            'auditUrl' => route('dashboard.audit', ['subject_type' => 'CustomerPayment', 'subject_id' => $payment->id]),
        ]);
    }

    private function refund(): CustomerRefund
    {
        return CustomerRefund::query()->findOrFail($this->refundId);
    }

    protected function refreshRecord(): void
    {
        $this->paymentToken = $this->refund()->payment()->firstOrFail()->stateToken();
    }
}
