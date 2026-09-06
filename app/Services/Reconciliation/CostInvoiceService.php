<?php

declare(strict_types=1);

namespace App\Services\Reconciliation;

use App\Data\Reconciliation\CostInvoiceInput;
use App\Data\Reconciliation\InvoiceLineInput;
use App\Enums\CostComponent;
use App\Enums\CostInvoiceEventType;
use App\Enums\CostInvoiceLineKind;
use App\Exceptions\Reconciliation\ReconciliationConflictException;
use App\Exceptions\Reconciliation\ReconciliationRuleException;
use App\Exceptions\Reconciliation\StaleReconciliationException;
use App\Models\AiProvider;
use App\Models\CostInvoice;
use App\Models\CostInvoiceEvent;
use App\Models\CostInvoiceLine;
use App\Services\Audit\AuditLogger;
use App\Support\Audit\AuditActions;
use App\Support\Billing\DecimalMath;
use App\Support\Payments\FinanceAuthorization;
use App\Support\Rbac\Permission;
use App\Support\Reconciliation\ReconciliationRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Supplier invoices as EVIDENCE (Phase E2). Lifecycle draft → confirmed →
 * voided | superseded through append-only events + a projection moved under
 * the invoice row lock with a state token (stale ⇒ refused). Confirmation
 * freezes facts and lines and proves Σ signed lines = total; it never makes
 * the total an actual cost — only a reconciliation does.
 */
final class CostInvoiceService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Idempotent draft recording (savepoint-based, PostgreSQL-safe): same key
     * + same facts ⇒ the existing invoice; different facts or the same
     * (counterparty, invoice_ref) under another key ⇒ conflict.
     */
    public function recordDraft(CostInvoiceInput $input): CostInvoice
    {
        FinanceAuthorization::assertCan(Permission::FinanceReconcile);
        $facts = $this->normalise($input);

        return DB::transaction(function () use ($facts): CostInvoice {
            try {
                return DB::transaction(function () use ($facts): CostInvoice { // savepoint
                    $invoice = CostInvoice::query()->create($facts + ['current_status' => CostInvoiceEventType::Draft->value, 'latest_event_id' => null]);
                    $event = $this->appendEvent($invoice, CostInvoiceEventType::Draft, null, null);
                    $invoice->forceFill(['latest_event_id' => $event->id])->save();

                    $this->audit->record(AuditActions::CostInvoiceRecorded, $invoice, [
                        'current_status' => ['from' => null, 'to' => CostInvoiceEventType::Draft->value],
                    ], ['component' => $invoice->component->value, 'counterparty_key' => $invoice->counterparty_key, 'invoice_ref' => $invoice->invoice_ref, 'currency' => $invoice->currency, 'total_amount' => (string) $invoice->total_amount, 'idempotency_key' => $invoice->idempotency_key]);

                    return $invoice;
                });
            } catch (UniqueConstraintViolationException) {
                $existing = CostInvoice::query()->where('idempotency_key', $facts['idempotency_key'])->first();

                if ($existing === null) {
                    throw new ReconciliationConflictException('مرجع الفاتورة مسجَّل مسبقًا لفاتورة أخرى بمفتاح idempotency مختلف.');
                }

                if (! $this->sameFacts($existing, $facts)) {
                    throw new ReconciliationConflictException("مفتاح idempotency [{$facts['idempotency_key']}] مستخدم لفاتورة بحقائق مختلفة (#{$existing->id}). لم يُكتب شيء.");
                }

                return $existing;
            }
        });
    }

    /** Add a signed line to a DRAFT invoice (under the invoice row lock). */
    public function addLine(InvoiceLineInput $input): CostInvoiceLine
    {
        FinanceAuthorization::assertCan(Permission::FinanceReconcile);
        $kind = CostInvoiceLineKind::tryFrom(strtolower(trim($input->kind))) ?? throw ReconciliationRuleException::of('kind', 'نوع السطر يجب أن يكون service أو tax أو credit أو other.');
        $scaled = ReconciliationRules::signedAmount($input->amount, 'amount', allowZero: false);
        $code = ReconciliationRules::requiredRef($input->descriptionCode, 32, 'description_code');

        if ($scaled > 0 && ! $kind->allowsPositive()) {
            throw ReconciliationRuleException::of('sign', 'سطر credit يجب أن يكون سالبًا أو صفرًا.');
        }

        if ($scaled < 0 && ! $kind->allowsNegative()) {
            throw ReconciliationRuleException::of('sign', "سطر {$kind->value} يجب أن يكون موجبًا (الائتمان يُسجَّل كسطر credit سالب).");
        }

        if ($input->lineNo <= 0) {
            throw ReconciliationRuleException::of('line_no', 'رقم السطر يجب أن يكون موجبًا.');
        }

        if (($input->periodStart === null) !== ($input->periodEnd === null) || ($input->periodStart !== null && ! $input->periodEnd->greaterThan($input->periodStart))) {
            throw ReconciliationRuleException::of('period', 'فترة السطر إمّا كاملة (بداية ونهاية، النهاية بعد البداية) أو غائبة.');
        }

        return DB::transaction(function () use ($input, $kind, $scaled, $code): CostInvoiceLine {
            $invoice = $this->lock($input->costInvoiceId);

            if ($invoice->current_status !== CostInvoiceEventType::Draft) {
                throw ReconciliationRuleException::of('lifecycle', "لا تُضاف أسطر إلا لمسودة (الحالة الحالية: {$invoice->current_status->value}).");
            }

            if (CostInvoiceLine::query()->where('cost_invoice_id', $invoice->id)->where('line_no', $input->lineNo)->exists()) {
                throw ReconciliationRuleException::of('line_no', "رقم السطر {$input->lineNo} مستخدم في هذه الفاتورة.");
            }

            $now = CarbonImmutable::now();
            $line = CostInvoiceLine::query()->create([
                'cost_invoice_id' => $invoice->id, 'line_no' => $input->lineNo, 'kind' => $kind->value, 'description_code' => $code,
                'amount' => ReconciliationRules::format($scaled), 'currency' => $invoice->currency,
                'period_start' => $input->periodStart, 'period_end' => $input->periodEnd,
                'actor_ref' => FinanceAuthorization::actorRef(), 'created_at' => $now,
            ]);

            $this->audit->record(AuditActions::CostInvoiceLineAdded, $invoice, [
                'line' => ['from' => null, 'to' => ['id' => $line->id, 'line_no' => $line->line_no, 'kind' => $kind->value, 'amount' => (string) $line->amount, 'currency' => $line->currency]],
            ], ['description_code' => $code]);

            return $line;
        });
    }

    /**
     * draft → confirmed: lock → token → at least one line and Σ signed lines
     * = total (exact scaled integers) → event → projection → audit. Facts and
     * lines are frozen from here; nothing becomes "actual cost".
     */
    public function confirm(int $invoiceId, string $expectedToken, ?string $evidenceRef = null): CostInvoice
    {
        FinanceAuthorization::assertCan(Permission::FinanceReconcile);
        $evidence = ReconciliationRules::ref($evidenceRef, 191, 'evidence_ref');

        return DB::transaction(function () use ($invoiceId, $expectedToken, $evidence): CostInvoice {
            $invoice = $this->lock($invoiceId);
            $this->assertToken($invoice, $expectedToken);

            if ($invoice->current_status !== CostInvoiceEventType::Draft) {
                throw ReconciliationRuleException::of('lifecycle', "التأكيد مسموح لمسودة فقط (الحالة الحالية: {$invoice->current_status->value}).");
            }

            $lines = CostInvoiceLine::query()->where('cost_invoice_id', $invoice->id)->count();
            $sum = DecimalMath::intFromDb(CostInvoiceLine::query()->where('cost_invoice_id', $invoice->id)->selectRaw('COALESCE(SUM(ROUND(amount * 1000000)), 0) AS s')->value('s'));
            $total = DecimalMath::toScaled(ltrim((string) $invoice->total_amount, '-'), ReconciliationRules::SCALE) * (str_starts_with((string) $invoice->total_amount, '-') ? -1 : 1);

            if ($lines === 0) {
                throw ReconciliationRuleException::of('lines', 'لا يمكن تأكيد فاتورة بلا أسطر.');
            }

            if ($sum !== $total) {
                throw ReconciliationRuleException::of('total_mismatch', 'مجموع الأسطر الموقَّعة ('.ReconciliationRules::format($sum).') لا يساوي إجمالي الفاتورة ('.ReconciliationRules::format($total).'). لم يُكتب شيء.');
            }

            return $this->move($invoice, CostInvoiceEventType::Confirmed, null, $evidence, []);
        });
    }

    /** confirmed | draft → voided. Existing evidence allocations stay as history; the query flags them. */
    public function void(int $invoiceId, string $expectedToken, string $reasonCode): CostInvoice
    {
        FinanceAuthorization::assertCan(Permission::FinanceReconcile);
        $reason = ReconciliationRules::requiredRef($reasonCode, 32, 'reason_code');

        return DB::transaction(function () use ($invoiceId, $expectedToken, $reason): CostInvoice {
            $invoice = $this->lock($invoiceId);
            $this->assertToken($invoice, $expectedToken);

            if (! in_array($invoice->current_status, [CostInvoiceEventType::Draft, CostInvoiceEventType::Confirmed], true)) {
                throw ReconciliationRuleException::of('lifecycle', "الإلغاء مسموح لمسودة أو فاتورة مؤكَّدة فقط (الحالة الحالية: {$invoice->current_status->value}).");
            }

            return $this->move($invoice, CostInvoiceEventType::Voided, $reason, null, []);
        });
    }

    /** confirmed → superseded by another CONFIRMED invoice of the same component / counterparty / currency. */
    public function supersede(int $invoiceId, string $expectedToken, int $replacementId, string $reasonCode): CostInvoice
    {
        FinanceAuthorization::assertCan(Permission::FinanceReconcile);
        $reason = ReconciliationRules::requiredRef($reasonCode, 32, 'reason_code');

        return DB::transaction(function () use ($invoiceId, $expectedToken, $replacementId, $reason): CostInvoice {
            $invoice = $this->lock($invoiceId);
            $this->assertToken($invoice, $expectedToken);
            $replacement = CostInvoice::query()->whereKey($replacementId)->first();

            if ($invoice->current_status !== CostInvoiceEventType::Confirmed) {
                throw ReconciliationRuleException::of('lifecycle', 'الاستبدال مسموح لفاتورة مؤكَّدة فقط.');
            }

            if ($replacement === null || $replacement->id === $invoice->id || ! $replacement->isConfirmed()
                || $replacement->component !== $invoice->component || $replacement->counterparty_key !== $invoice->counterparty_key || $replacement->currency !== $invoice->currency) {
                throw ReconciliationRuleException::of('replacement', 'الفاتورة البديلة يجب أن تكون مؤكَّدة ومختلفة وبنفس المكوّن والطرف والعملة.');
            }

            return $this->move($invoice, CostInvoiceEventType::Superseded, $reason, null, ['superseded_by_id' => $replacement->id]);
        });
    }

    /**
     * @param  array<string, mixed>  $extraProjection
     */
    private function move(CostInvoice $invoice, CostInvoiceEventType $to, ?string $reason, ?string $evidence, array $extraProjection): CostInvoice
    {
        $from = $invoice->current_status;
        $event = $this->appendEvent($invoice, $to, $reason, $evidence);
        $invoice->forceFill(['current_status' => $to->value, 'latest_event_id' => $event->id] + $extraProjection)->save();

        $this->audit->record(AuditActions::CostInvoiceTransitioned, $invoice, [
            'current_status' => ['from' => $from->value, 'to' => $to->value],
        ], ['event_id' => $event->id, 'reason_code' => $reason, 'total_amount' => (string) $invoice->total_amount, 'currency' => $invoice->currency] + $extraProjection);

        return $invoice;
    }

    private function appendEvent(CostInvoice $invoice, CostInvoiceEventType $type, ?string $reason, ?string $evidence): CostInvoiceEvent
    {
        $now = CarbonImmutable::now();

        return CostInvoiceEvent::query()->create([
            'cost_invoice_id' => $invoice->id, 'event_type' => $type->value, 'occurred_at' => $now,
            'actor_ref' => FinanceAuthorization::actorRef(), 'reason_code' => $reason, 'evidence_ref' => $evidence, 'created_at' => $now,
        ]);
    }

    private function lock(int $invoiceId): CostInvoice
    {
        return CostInvoice::query()->whereKey($invoiceId)->lockForUpdate()->first()
            ?? throw ReconciliationRuleException::of('invoice', 'الفاتورة غير موجودة.');
    }

    private function assertToken(CostInvoice $invoice, string $expected): void
    {
        if (! hash_equals($invoice->stateToken(), $expected)) {
            throw new StaleReconciliationException("حالة الفاتورة تغيّرت (المتوقع {$expected}، الحالي {$invoice->stateToken()}). لم يُكتب شيء.");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function normalise(CostInvoiceInput $input): array
    {
        $component = ReconciliationRules::component($input->component);
        $counterparty = ReconciliationRules::requiredRef($input->counterpartyKey, 64, 'counterparty_key');

        if ($component === CostComponent::Provider && ! AiProvider::query()->where('key', $counterparty)->exists()) {
            throw ReconciliationRuleException::of('counterparty_key', "مفتاح الطرف [{$counterparty}] لا يطابق مزوّد ذكاء معروفًا.");
        }

        $key = trim($input->idempotencyKey);

        if ($key === '' || mb_strlen($key) > 191) {
            throw ReconciliationRuleException::of('idempotency_key', 'مفتاح idempotency إلزامي (حتى 191 حرفًا).');
        }

        ReconciliationRules::notInFuture($input->issuedAt, 'issued_at');

        if (! $input->periodEnd->greaterThan($input->periodStart)) {
            throw ReconciliationRuleException::of('period', 'نهاية فترة الفاتورة يجب أن تكون بعد بدايتها.');
        }

        return [
            'component' => $component->value,
            'counterparty_key' => $counterparty,
            'invoice_ref' => ReconciliationRules::ref($input->invoiceRef, 191, 'invoice_ref'),
            'idempotency_key' => $key,
            'issued_at' => $input->issuedAt,
            'period_start' => $input->periodStart,
            'period_end' => $input->periodEnd,
            'currency' => ReconciliationRules::currency($input->currency, 'currency'),
            'total_amount' => ReconciliationRules::format(ReconciliationRules::signedAmount($input->totalAmount, 'total_amount', allowZero: true)),
            'evidence_ref' => ReconciliationRules::ref($input->evidenceRef, 191, 'evidence_ref'),
            'recorded_by_ref' => FinanceAuthorization::actorRef(),
        ];
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function sameFacts(CostInvoice $existing, array $facts): bool
    {
        return $existing->component->value === $facts['component']
            && $existing->counterparty_key === $facts['counterparty_key']
            && $existing->invoice_ref === $facts['invoice_ref']
            && $existing->currency === $facts['currency']
            && (string) $existing->total_amount === $facts['total_amount']
            && $existing->period_start->equalTo($facts['period_start'])
            && $existing->period_end->equalTo($facts['period_end']);
    }
}
