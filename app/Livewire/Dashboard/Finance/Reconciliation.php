<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Finance;

use App\Data\Reconciliation\CostInvoiceInput;
use App\Data\Reconciliation\EvidenceAllocation;
use App\Data\Reconciliation\InvoiceLineInput;
use App\Data\Reconciliation\ReconciliationInput;
use App\Exceptions\Reconciliation\ReconciliationConflictException;
use App\Exceptions\Reconciliation\ReconciliationRuleException;
use App\Exceptions\Reconciliation\StaleReconciliationException;
use App\Models\CostInvoice;
use App\Models\CostInvoiceLine;
use App\Models\CostReconciliationScope;
use App\Services\Reconciliation\CostInvoiceService;
use App\Services\Reconciliation\CostReconciliationService;
use App\Services\Reconciliation\ReconciledCostQuery;
use App\Support\Rbac\Permission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Minimal admin page for Phase E2 (the full finance UI is Phase E5) under
 * `finance.reconcile`: Record Invoice · Add Line · Confirm Invoice ·
 * Void / Supersede · Create Reconciliation (explicit evidence allocations) ·
 * Confirm Zero (typed ZERO) · Add Adjustment, plus a plain per-scope
 * reconciled-cost table for a month range. Mount and EVERY action re-check
 * the permission server-side; the services check again. No dashboard, no
 * CSV, no cash contribution, no gross profit. No PII, no free text.
 */
#[Title('تسوية التكلفة | سَنَد')]
#[Layout('components.layouts.dashboard')]
class Reconciliation extends Component
{
    private const DATE = 'Y-m-d';

    // ---- Record invoice ----
    public string $invComponent = 'provider';

    public string $invCounterparty = '';

    public string $invKey = '';

    public string $invRef = '';

    public string $invIssuedAt = '';

    public string $invPeriodStart = '';

    public string $invPeriodEnd = '';

    public string $invCurrency = '';

    public string $invTotal = '';

    public string $invEvidence = '';

    // ---- Add line ----
    public string $lineInvoiceId = '';

    public string $lineNo = '';

    public string $lineKind = 'service';

    public string $lineCode = '';

    public string $lineAmount = '';

    public string $linePeriodStart = '';

    public string $linePeriodEnd = '';

    // ---- Confirm / void / supersede ----
    public string $lcInvoiceId = '';

    public string $lcToken = '';

    public string $lcReason = '';

    public string $lcReplacementId = '';

    // ---- Reconciliation ----
    public string $recComponent = 'provider';

    public string $recCounterparty = '';

    public string $recMonth = '';

    public string $recCurrency = '';

    public string $recExpected = '';

    public string $recSource = 'invoice';

    /** @var list<array{line: string, amount: string, fx_rate_id: string}> */
    public array $recAllocations = [['line' => '', 'amount' => '', 'fx_rate_id' => ''], ['line' => '', 'amount' => '', 'fx_rate_id' => ''], ['line' => '', 'amount' => '', 'fx_rate_id' => '']];

    public string $recAmount = '';

    public string $recReason = '';

    public string $recEvidence = '';

    public string $recTyped = '';

    // ---- Adjustment ----
    public string $adjReconciliationId = '';

    public string $adjAmount = '';

    public string $adjReason = '';

    public string $adjEvidence = '';

    // ---- Summary window ----
    #[Url]
    public string $fromMonth = '';

    #[Url]
    public string $toMonth = '';

    public ?string $notice = null;

    public function mount(): void
    {
        $this->authorizeReconcile();
        $now = CarbonImmutable::now('UTC');
        $this->invKey = 'ui:'.Str::uuid()->toString();
        $this->invIssuedAt = $now->format(self::DATE);
        $this->invPeriodStart = $now->startOfMonth()->format(self::DATE);
        $this->invPeriodEnd = $now->startOfMonth()->addMonth()->format(self::DATE);
        $this->recMonth = $now->subMonth()->format('Y-m');

        if ($this->fromMonth === '' || $this->toMonth === '') {
            $this->toMonth = $now->format('Y-m');
            $this->fromMonth = $now->subMonths(2)->format('Y-m');
        }
    }

    public function recordInvoice(CostInvoiceService $service): void
    {
        $this->authorizeReconcile();
        $this->resetErrorBag('invoice');
        $this->notice = null;

        try {
            $invoice = $service->recordDraft(new CostInvoiceInput(
                component: $this->invComponent, counterpartyKey: $this->invCounterparty, idempotencyKey: $this->invKey,
                issuedAt: $this->date($this->invIssuedAt, 'تاريخ الإصدار'), periodStart: $this->date($this->invPeriodStart, 'بداية الفترة'), periodEnd: $this->date($this->invPeriodEnd, 'نهاية الفترة'),
                currency: $this->invCurrency, totalAmount: $this->invTotal, invoiceRef: self::optional($this->invRef), evidenceRef: self::optional($this->invEvidence),
            ));
        } catch (ReconciliationRuleException|ReconciliationConflictException|InvalidArgumentException $e) {
            $this->addError('invoice', $e->getMessage());

            return;
        }

        $this->notice = $invoice->wasRecentlyCreated
            ? "سُجِّلت الفاتورة #{$invoice->id} كمسودة (token {$invoice->stateToken()}) — {$invoice->total_amount} {$invoice->currency}. أضف الأسطر ثم أكّد."
            : "الفاتورة #{$invoice->id} مسجَّلة مسبقًا بنفس المفتاح والحقائق؛ لم يُكتب شيء جديد.";
        $this->reset('invCounterparty', 'invRef', 'invTotal', 'invEvidence');
        $this->invKey = 'ui:'.Str::uuid()->toString();
    }

    public function addLine(CostInvoiceService $service): void
    {
        $this->authorizeReconcile();
        $this->resetErrorBag('line');
        $this->notice = null;

        try {
            $line = $service->addLine(new InvoiceLineInput(
                costInvoiceId: $this->positiveInt($this->lineInvoiceId, 'معرّف الفاتورة'), lineNo: $this->positiveInt($this->lineNo, 'رقم السطر'), kind: $this->lineKind,
                descriptionCode: $this->lineCode, amount: $this->lineAmount,
                periodStart: trim($this->linePeriodStart) === '' ? null : $this->date($this->linePeriodStart, 'بداية فترة السطر'),
                periodEnd: trim($this->linePeriodEnd) === '' ? null : $this->date($this->linePeriodEnd, 'نهاية فترة السطر'),
            ));
        } catch (ReconciliationRuleException|InvalidArgumentException $e) {
            $this->addError('line', $e->getMessage());

            return;
        }

        $this->notice = "أُضيف السطر #{$line->id} ({$line->kind->value} {$line->amount} {$line->currency}) للفاتورة #{$line->cost_invoice_id}.";
        $this->reset('lineNo', 'lineCode', 'lineAmount', 'linePeriodStart', 'linePeriodEnd');
    }

    public function confirmInvoice(CostInvoiceService $service): void
    {
        $this->lifecycle(fn () => $service->confirm($this->positiveInt($this->lcInvoiceId, 'معرّف الفاتورة'), trim($this->lcToken)), 'أُكِّدت الفاتورة');
    }

    public function voidInvoice(CostInvoiceService $service): void
    {
        $this->lifecycle(fn () => $service->void($this->positiveInt($this->lcInvoiceId, 'معرّف الفاتورة'), trim($this->lcToken), $this->lcReason), 'أُلغيت الفاتورة');
    }

    public function supersedeInvoice(CostInvoiceService $service): void
    {
        $this->lifecycle(fn () => $service->supersede($this->positiveInt($this->lcInvoiceId, 'معرّف الفاتورة'), trim($this->lcToken), $this->positiveInt($this->lcReplacementId, 'معرّف الفاتورة البديلة'), $this->lcReason), 'استُبدلت الفاتورة');
    }

    public function reconcile(CostReconciliationService $service): void
    {
        $this->authorizeReconcile();
        $this->resetErrorBag('reconciliation');
        $this->notice = null;

        try {
            $allocations = [];
            foreach ($this->recAllocations as $row) {
                if (trim((string) ($row['line'] ?? '')) === '' && trim((string) ($row['amount'] ?? '')) === '') {
                    continue;
                }
                $fxRateId = trim((string) ($row['fx_rate_id'] ?? ''));
                $allocations[] = new EvidenceAllocation($this->positiveInt((string) ($row['line'] ?? ''), 'معرّف السطر'), (string) ($row['amount'] ?? ''), $fxRateId === '' ? null : $this->positiveInt($fxRateId, 'fx_rate_id'));
            }

            $reconciliation = $service->reconcile(new ReconciliationInput(
                component: $this->recComponent, counterpartyKey: $this->recCounterparty, month: $this->recMonth, currency: $this->recCurrency,
                expectedCurrentReconciliationId: trim($this->recExpected) === '' ? null : $this->positiveInt($this->recExpected, 'التسوية الحالية المتوقعة'),
                source: $this->recSource, allocations: $allocations, reconciledAmount: self::optional($this->recAmount),
                reasonCode: self::optional($this->recReason), evidenceRef: self::optional($this->recEvidence), typedConfirmation: self::optional($this->recTyped),
            ));
        } catch (ReconciliationRuleException|StaleReconciliationException|InvalidArgumentException $e) {
            $this->addError('reconciliation', $e->getMessage());

            return;
        }

        $label = $reconciliation->source->value === 'confirmed_zero' ? 'CONFIRMED ZERO' : $reconciliation->reconciled_amount.' '.$reconciliation->currency;
        $this->notice = "سُجِّلت التسوية #{$reconciliation->id} لنطاق {$reconciliation->component->value}/{$reconciliation->counterparty_key}/".$reconciliation->period_start->format('Y-m')." = {$label} (Calculated known {$reconciliation->calculated_known_amount}, coverage {$reconciliation->cost_coverage_status->value}).";
        $this->reset('recAllocations', 'recAmount', 'recReason', 'recEvidence', 'recTyped');
        $this->recExpected = (string) $reconciliation->id;
    }

    public function adjust(CostReconciliationService $service): void
    {
        $this->authorizeReconcile();
        $this->resetErrorBag('adjustment');
        $this->notice = null;

        try {
            $adjustment = $service->adjust($this->positiveInt($this->adjReconciliationId, 'معرّف التسوية'), $this->adjAmount, $this->adjReason, $this->adjEvidence);
        } catch (ReconciliationRuleException|StaleReconciliationException|InvalidArgumentException $e) {
            $this->addError('adjustment', $e->getMessage());

            return;
        }

        $this->notice = "أُضيف التعديل #{$adjustment->id} ({$adjustment->amount} {$adjustment->currency}) على التسوية #{$adjustment->cost_reconciliation_id}؛ المبلغ الأساسي لم يتغيّر.";
        $this->reset('adjAmount', 'adjReason', 'adjEvidence');
    }

    public function render(ReconciledCostQuery $query)
    {
        $this->authorizeReconcile();

        $summary = [];
        $windowError = null;

        try {
            $summary = $query->summarise($this->fromMonth, $this->toMonth);
        } catch (ReconciliationRuleException|InvalidArgumentException $e) {
            $windowError = $e->getMessage();
        }

        return view('livewire.dashboard.finance.reconciliation', [
            'summary' => $summary,
            'windowError' => $windowError,
            'invoices' => CostInvoice::query()->orderByDesc('id')->limit(25)->get(),
            'lines' => CostInvoiceLine::query()->orderByDesc('id')->limit(40)->get(),
            'scopes' => CostReconciliationScope::query()->orderByDesc('id')->limit(25)->get(),
        ]);
    }

    private function lifecycle(callable $fn, string $done): void
    {
        $this->authorizeReconcile();
        $this->resetErrorBag('lifecycle');
        $this->notice = null;

        try {
            $invoice = $fn();
        } catch (ReconciliationRuleException|StaleReconciliationException|InvalidArgumentException $e) {
            $this->addError('lifecycle', $e->getMessage());

            return;
        }

        $this->notice = "{$done} #{$invoice->id} (الحالة {$invoice->current_status->value}، token {$invoice->stateToken()}).";
        $this->reset('lcToken', 'lcReason', 'lcReplacementId');
    }

    private function authorizeReconcile(): void
    {
        abort_unless(auth()->user()?->can(Permission::FinanceReconcile->value) ?? false, 403);
    }

    private static function optional(string $value): ?string
    {
        return trim($value) === '' ? null : trim($value);
    }

    private function positiveInt(string $value, string $label): int
    {
        $value = trim($value);

        if (! ctype_digit($value) || (int) $value <= 0) {
            throw new InvalidArgumentException("{$label} يجب أن يكون رقمًا صحيحًا موجبًا.");
        }

        return (int) $value;
    }

    private function date(string $value, string $label): CarbonImmutable
    {
        try {
            $at = CarbonImmutable::createFromFormat('!'.self::DATE, trim($value), 'UTC');
        } catch (\Throwable) {
            $at = false;
        }

        if ($at === false) {
            throw new InvalidArgumentException("{$label} بصيغة غير صالحة (YYYY-MM-DD، UTC).");
        }

        return $at;
    }
}
