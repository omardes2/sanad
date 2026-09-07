<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Finance;

use App\Data\Reconciliation\InvoiceLineInput;
use App\Enums\CostInvoiceEventType;
use App\Enums\CostInvoiceLineKind;
use App\Livewire\Dashboard\Finance\Concerns\HandlesReconciliationActions;
use App\Models\CostInvoice;
use App\Models\CostInvoiceAllocation;
use App\Models\CostInvoiceEvent;
use App\Models\CostInvoiceLine;
use App\Models\CostReconciliation;
use App\Services\Reconciliation\CostInvoiceService;
use App\Services\Reconciliation\CostReconciliationService;
use App\Services\Reconciliation\ReconciliationLedgerView;
use App\Support\Rbac\Permission;
use App\Support\Reconciliation\ReconciliationRules;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Cost invoice detail (Phase E5.2b). Facts as the E2 services recorded them:
 * identity, frozen facts, the lifecycle token this page rendered with, the
 * event trail, the signed lines with Σ vs the invoice total, per-line
 * allocatable state, source share already attributed and remaining (display
 * only — the service caps under its own lock, never clips), the
 * reconciliations that used this invoice as evidence, superseded-by, and the
 * audit link (subject CostInvoice). Actions — Add Line (draft only), Confirm,
 * Void, Supersede — go through CostInvoiceService with the rendered token as a
 * hidden field; stale ⇒ refreshed, never re-run. No model write here.
 */
#[Title('تفاصيل فاتورة التكلفة | سَنَد')]
#[Layout('components.layouts.dashboard')]
class CostInvoiceDetail extends Component
{
    use HandlesReconciliationActions;

    public int $invoiceId;

    /** The lifecycle token this page rendered with — sent back as a hidden field, compared before the service. */
    public string $invoiceToken = '';

    public ?string $confirming = null; // line | confirm | void | supersede

    // ---- add line ----
    public string $lineKey = '';

    public string $lineNo = '';

    public string $lineKind = 'service';

    public string $lineCode = '';

    public string $lineAmount = '';

    public string $linePeriodStart = '';

    public string $linePeriodEnd = '';

    // ---- lifecycle ----
    public string $lcReason = '';

    public string $lcEvidence = '';

    public string $lcReplacementId = '';

    public ?string $notice = null;

    public function mount(CostInvoice $invoice): void
    {
        $this->authorizeManage();
        $this->invoiceId = $invoice->id;
        $this->invoiceToken = $invoice->stateToken();
        $this->lineKey = self::freshKey();
        $this->lineNo = (string) ((int) CostInvoiceLine::query()->where('cost_invoice_id', $invoice->id)->max('line_no') + 1);
    }

    public function openConfirm(string $action): void
    {
        $this->authorizeManage();
        $this->confirming = in_array($action, ['line', 'confirm', 'void', 'supersede'], true) ? $action : null;
    }

    public function closeConfirm(): void
    {
        $this->confirming = null;
    }

    /** Add Line — no service key: the parent invoice lock + unique (invoice, line_no) are the contract; a duplicate line_no is refused, never replayed. */
    public function addLine(CostInvoiceService $service): void
    {
        $ok = $this->attempt('line', $this->lineKey, function () use ($service): void {
            $invoice = $this->invoice();
            $this->assertRenderedToken($this->invoiceToken, $invoice->stateToken());

            $line = $service->addLine(new InvoiceLineInput(
                costInvoiceId: $invoice->id, lineNo: $this->positiveInt($this->lineNo, 'رقم السطر'), kind: $this->lineKind,
                descriptionCode: $this->lineCode, amount: $this->lineAmount,
                periodStart: trim($this->linePeriodStart) === '' ? null : $this->utcDate($this->linePeriodStart, 'بداية فترة السطر'),
                periodEnd: trim($this->linePeriodEnd) === '' ? null : $this->utcDate($this->linePeriodEnd, 'نهاية فترة السطر'),
            ));
            $this->notice = "أُضيف السطر #{$line->id} (line_no {$line->line_no}، {$line->kind->value} {$line->amount} {$line->currency}).";
        });

        if ($ok) {
            $this->reset('lineCode', 'lineAmount', 'linePeriodStart', 'linePeriodEnd', 'confirming');
            $this->lineKey = self::freshKey();
            $this->lineNo = (string) ((int) CostInvoiceLine::query()->where('cost_invoice_id', $this->invoiceId)->max('line_no') + 1);
        }
    }

    public function confirmInvoice(CostInvoiceService $service): void
    {
        $this->lifecycle('confirm', fn (string $token) => $service->confirm($this->invoiceId, $token, self::optional($this->lcEvidence)), 'أُكِّدت الفاتورة');
    }

    public function voidInvoice(CostInvoiceService $service): void
    {
        $this->lifecycle('void', fn (string $token) => $service->void($this->invoiceId, $token, $this->requiredReason()), 'أُلغيت الفاتورة');
    }

    public function supersedeInvoice(CostInvoiceService $service): void
    {
        $this->lifecycle('supersede', fn (string $token) => $service->supersede($this->invoiceId, $token, $this->positiveInt($this->lcReplacementId, 'الفاتورة البديلة'), $this->requiredReason()), 'استُبدلت الفاتورة');
    }

    public function render(ReconciliationLedgerView $ledger)
    {
        $this->authorizeManage();
        $user = auth()->user();
        $invoice = $this->invoice();

        $lines = CostInvoiceLine::query()->where('cost_invoice_id', $invoice->id)->orderBy('line_no')->get();
        $allocated = $ledger->lineAllocated($lines->pluck('id')->all());
        $lineSum = 0;
        $lineRows = [];
        foreach ($lines as $line) {
            $amount = CostReconciliationService::scaledOf((string) $line->amount);
            $lineSum += $amount;
            $used = $allocated[$line->id] ?? 0;
            $lineRows[] = [
                'line' => $line,
                'allocatable' => $line->kind->isAllocatable(),
                'allocated' => ReconciliationRules::format($used),
                'remaining' => $line->kind->isAllocatable() ? ReconciliationRules::format($amount < 0 ? -max(0, abs($amount) - abs($used)) : max(0, $amount - $used)) : null,
            ];
        }
        $total = CostReconciliationService::scaledOf((string) $invoice->total_amount);

        $evidence = CostInvoiceAllocation::query()->where('cost_invoice_id', $invoice->id)->orderBy('id')->get();
        $reconciliations = CostReconciliation::query()->whereIn('id', $evidence->pluck('cost_reconciliation_id')->unique()->all())->get()->keyBy('id');

        $warnings = [];
        if ($invoice->current_status === CostInvoiceEventType::Voided) {
            $warnings[] = 'EVIDENCE VOIDED · reconciliations that drew on this invoice carry the EVIDENCE VOIDED flag (EVIDENCE_STALE for period close)';
        } elseif ($invoice->current_status === CostInvoiceEventType::Superseded) {
            $warnings[] = 'EVIDENCE SUPERSEDED (#'.$invoice->id.' → #'.$invoice->superseded_by_id.') · reconciliations that drew on this invoice carry the EVIDENCE SUPERSEDED flag';
        }
        if ($invoice->current_status === CostInvoiceEventType::Draft && $lineSum !== $total) {
            $warnings[] = 'TOTAL MISMATCH · Σ signed lines '.ReconciliationRules::format($lineSum).' ≠ invoice total '.ReconciliationRules::format($total).' — confirmation will be refused (total_mismatch)';
        }

        $isDraft = $invoice->current_status === CostInvoiceEventType::Draft;
        $isConfirmed = $invoice->current_status === CostInvoiceEventType::Confirmed;

        return view('livewire.dashboard.finance.cost-invoice-detail', [
            'invoice' => $invoice,
            'events' => CostInvoiceEvent::query()->where('cost_invoice_id', $invoice->id)->orderBy('id')->get(),
            'lineRows' => $lineRows,
            'lineSum' => ReconciliationRules::format($lineSum),
            'total' => ReconciliationRules::format($total),
            'sumMatches' => $lineSum === $total,
            'evidence' => $evidence,
            'reconciliations' => $reconciliations,
            'warnings' => $warnings,
            'canAddLine' => $isDraft,
            'canConfirm' => $isDraft,
            'canVoid' => $isDraft || $isConfirmed,
            'canSupersede' => $isConfirmed,
            'replacements' => $this->confirming === 'supersede' ? $this->replacementCandidates($invoice) : collect(),
            'kinds' => array_map(static fn (CostInvoiceLineKind $k): string => $k->value, CostInvoiceLineKind::cases()),
            'canAudit' => (bool) $user->can(Permission::AuditView->value),
            'auditUrl' => route('dashboard.audit', ['subject_type' => 'CostInvoice', 'subject_id' => $invoice->id]),
        ]);
    }

    /** @param callable(string): CostInvoice $call */
    private function lifecycle(string $form, callable $call, string $done): void
    {
        $token = $this->invoiceToken; // the token this page rendered with — never re-read before the call

        $ok = $this->attempt($form, $form.':'.$token, function () use ($call, $token, $done): void {
            $invoice = $call($token);
            $this->invoiceToken = $invoice->stateToken();
            $this->notice = "{$done} #{$invoice->id} (الحالة {$invoice->current_status->value}، token {$invoice->stateToken()}). الفاتورة دليل فقط؛ لا تكلفة فعلية بلا تسوية.";
        });

        if ($ok) {
            $this->reset('lcReason', 'lcEvidence', 'lcReplacementId', 'confirming');
        }
    }

    private function requiredReason(): string
    {
        $reason = ReconciliationRules::ref($this->lcReason, 32, 'reason_code');

        if ($reason === null) {
            throw new \InvalidArgumentException('رمز السبب إلزامي لهذا الإجراء (حتى 32 حرفًا).');
        }

        return $reason;
    }

    /** Only what the service accepts as a replacement: another CONFIRMED invoice of the same component / counterparty / currency. */
    private function replacementCandidates(CostInvoice $invoice)
    {
        return CostInvoice::query()->where('component', $invoice->component->value)->where('counterparty_key', $invoice->counterparty_key)->where('currency', $invoice->currency)
            ->where('current_status', CostInvoiceEventType::Confirmed->value)->whereKeyNot($invoice->id)->orderByDesc('id')->limit(50)->get();
    }

    private function invoice(): CostInvoice
    {
        return CostInvoice::query()->findOrFail($this->invoiceId);
    }

    protected function refreshRecord(): void
    {
        $this->invoiceToken = $this->invoice()->stateToken();
    }
}
