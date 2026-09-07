<?php

declare(strict_types=1);

namespace App\Services\Reconciliation;

use App\Data\Reconciliation\EvidenceAllocation;
use App\Data\Reconciliation\ReconciliationInput;
use App\Enums\CostComponent;
use App\Enums\CostInvoiceEventType;
use App\Enums\ReconciliationSource;
use App\Exceptions\Fx\FxRuleException;
use App\Exceptions\Fx\StaleFxException;
use App\Exceptions\Reconciliation\ReconciliationConflictException;
use App\Exceptions\Reconciliation\ReconciliationRuleException;
use App\Exceptions\Reconciliation\StaleReconciliationException;
use App\Models\CostAdjustment;
use App\Models\CostInvoice;
use App\Models\CostInvoiceAllocation;
use App\Models\CostInvoiceLine;
use App\Models\CostReconciliation;
use App\Models\CostReconciliationScope;
use App\Services\Audit\AuditLogger;
use App\Services\Fx\ReportingConversionService;
use App\Support\Audit\AuditActions;
use App\Support\Billing\DecimalMath;
use App\Support\Fx\FxMath;
use App\Support\Payments\FinanceAuthorization;
use App\Support\Rbac\Permission;
use App\Support\Reconciliation\ReconciliationRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Cost reconciliation (Phase E2), one calendar month UTC per scope:
 *
 *  reconcile(): find-or-create the scope row → FOR UPDATE → verify the
 *  caller's expected current pointer (stale ⇒ refused, never last-writer-
 *  wins) → freeze the ledger snapshot under the lock → build the reconciled
 *  amount from EXPLICIT evidence allocations (invoice lines locked through
 *  their invoice rows, |Σ| ≤ |line| across all reconciliations, signed like
 *  the line, fully accepted or fully refused), or from an evidenced manual
 *  amount, or from a typed CONFIRMED ZERO attestation → insert the append-
 *  only reconciliation (supersedes the previous pointer) → move the scope
 *  pointer → audit — one transaction. Never an invoice total, never FX.
 *
 *  adjust(): a signed, reasoned, evidenced correction appended to the scope's
 *  CURRENT reconciliation under the same lock; the base amount never changes.
 */
final class CostReconciliationService
{
    public const ZERO_CONFIRMATION = 'ZERO';

    public function __construct(private readonly AuditLogger $audit, private readonly LedgerSnapshotter $ledger) {}

    public function reconcile(ReconciliationInput $input): CostReconciliation
    {
        FinanceAuthorization::assertCan(Permission::FinanceReconcile);

        $component = ReconciliationRules::component($input->component);
        $counterparty = ReconciliationRules::requiredRef($input->counterpartyKey, 64, 'counterparty_key');
        [$start, $end] = ReconciliationRules::month($input->month);
        $currency = ReconciliationRules::currency($input->currency, 'currency');
        $source = ReconciliationSource::tryFrom(trim($input->source)) ?? throw ReconciliationRuleException::of('source', 'مصدر التسوية يجب أن يكون invoice أو manual_evidenced أو confirmed_zero.');
        $reason = ReconciliationRules::ref($input->reasonCode, 32, 'reason_code');
        $evidence = ReconciliationRules::ref($input->evidenceRef, 191, 'evidence_ref');

        // Source-specific input rules — all before any lock.
        $manualScaled = null;
        $requests = [];

        switch ($source) {
            case ReconciliationSource::Invoice:
                if ($input->allocations === []) {
                    throw ReconciliationRuleException::of('allocations', 'التسوية من الفواتير تتطلب تخصيص دليل واحدًا على الأقل (لا تُستخدم إجماليات الفواتير تلقائيًا).');
                }
                foreach ($input->allocations as $allocation) {
                    if (! $allocation instanceof EvidenceAllocation) {
                        throw ReconciliationRuleException::of('allocations', 'تخصيص غير صالح.');
                    }
                    $requests[] = [$allocation->costInvoiceLineId, ReconciliationRules::signedAmount($allocation->amount, 'allocation_amount'), $allocation->fxRateId];
                }
                break;
            case ReconciliationSource::ManualEvidenced:
                if ($input->allocations !== []) {
                    throw ReconciliationRuleException::of('allocations', 'التسوية اليدوية لا تحمل تخصيصات فواتير.');
                }
                $manualScaled = ReconciliationRules::positiveAmount((string) $input->reconciledAmount, 'reconciled_amount');
                if ($reason === null || $evidence === null) {
                    throw ReconciliationRuleException::of('evidence', 'التسوية اليدوية تتطلب رمز سبب ومرجع دليل.');
                }
                break;
            case ReconciliationSource::ConfirmedZero:
                if ($input->allocations !== [] || ($input->reconciledAmount !== null && trim($input->reconciledAmount) !== '' && ReconciliationRules::signedAmount($input->reconciledAmount, 'reconciled_amount', allowZero: true) !== 0)) {
                    throw ReconciliationRuleException::of('confirmed_zero', 'الصفر المؤكَّد لا يحمل مبلغًا ولا تخصيصات.');
                }
                if ($reason === null || $evidence === null) {
                    throw ReconciliationRuleException::of('evidence', 'الصفر المؤكَّد شهادة مالية: رمز السبب ومرجع الدليل إلزاميان.');
                }
                if (($input->typedConfirmation ?? '') !== self::ZERO_CONFIRMATION) {
                    throw ReconciliationRuleException::of('typed_confirmation', 'اكتب ZERO حرفيًا لتأكيد أن التكلفة الفعلية صفر.');
                }
                break;
        }

        return DB::transaction(function () use ($input, $component, $counterparty, $start, $end, $currency, $source, $reason, $evidence, $manualScaled, $requests): CostReconciliation {
            $scope = $this->lockScope($component, $counterparty, $start, $end, $currency);

            if ($scope->current_reconciliation_id !== $input->expectedCurrentReconciliationId) {
                throw new StaleReconciliationException('تسوية هذا النطاق تغيّرت (المتوقع '.($input->expectedCurrentReconciliationId ?? 'none').'، الحالي '.($scope->current_reconciliation_id ?? 'none').'). حدّث الصفحة وأعد المحاولة من التسوية الحالية. لم يُكتب شيء.');
            }

            $snapshot = $this->ledger->capture($component, $counterparty, $start, $end, $currency);

            // Evidence: lock the invoices (ordered by id) and check every line under that lock.
            $evidenceRows = [];
            $reconciledScaled = 0;

            if ($source === ReconciliationSource::Invoice) {
                $lines = CostInvoiceLine::query()->whereIn('id', array_column($requests, 0))->get()->keyBy('id');

                foreach ($requests as [$lineId]) {
                    if (! $lines->has($lineId)) {
                        throw ReconciliationRuleException::of('line', "سطر الفاتورة #{$lineId} غير موجود.");
                    }
                }

                $invoiceIds = $lines->pluck('cost_invoice_id')->unique()->sort()->values();
                $invoices = CostInvoice::query()->whereIn('id', $invoiceIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

                foreach ($requests as [$lineId, $scaled, $fxRateId]) {
                    /** @var CostInvoiceLine $line */
                    $line = $lines->get($lineId);
                    /** @var CostInvoice $invoice */
                    $invoice = $invoices->get($line->cost_invoice_id);

                    if ($invoice->current_status !== CostInvoiceEventType::Confirmed) {
                        throw ReconciliationRuleException::of('invoice_status', "الفاتورة #{$invoice->id} ليست مؤكَّدة ({$invoice->current_status->value})؛ الدليل يجب أن يكون فاتورة مؤكَّدة.");
                    }

                    if ($invoice->component !== $component || $invoice->counterparty_key !== $counterparty) {
                        throw ReconciliationRuleException::of('scope_mismatch', "الفاتورة #{$invoice->id} تخص مكوّنًا/طرفًا آخر غير نطاق التسوية.");
                    }

                    // Phase E3 — allocation-level FX: the share is in the LINE currency; a
                    // cross-currency share needs the exact quote dated on the invoice's
                    // issued_at (no lookup), verified as the current revision and frozen here.
                    $fx = null;
                    $convertedScaled = $scaled;

                    if ($invoice->currency !== $currency) {
                        if ($fxRateId === null) {
                            throw ReconciliationRuleException::of('FX_REQUIRED', "الفاتورة #{$invoice->id} بعملة {$invoice->currency} ونطاق التسوية {$currency}: حدّد fx_rate_id صريحًا لسعر بتاريخ إصدار الفاتورة (".$invoice->issued_at->utc()->format('Y-m-d').'). لا تحويل ضمني.');
                        }

                        try {
                            $rate = ReportingConversionService::acceptedRate($fxRateId, $invoice->currency, $currency, $invoice->issued_at->utc()->format('Y-m-d'));
                        } catch (FxRuleException $e) {
                            throw ReconciliationRuleException::of($e->rule, $e->getMessage());
                        } catch (StaleFxException $e) {
                            throw new StaleReconciliationException($e->getMessage());
                        }

                        $direction = FxMath::directionFor($rate->base_currency, $rate->quote_currency, $invoice->currency, $currency);
                        $convertedScaled = ReconciliationRules::signedAmount(FxMath::convert(ReconciliationRules::format($scaled), ReconciliationRules::SCALE, (string) $rate->rate, $direction, ReconciliationRules::SCALE), 'allocation_amount', allowZero: true);
                        $fx = ['fx_rate_id' => $rate->id, 'fx_rate_snapshot' => (string) $rate->rate, 'fx_direction' => $direction->value, 'fx_rate_date' => $rate->rateDate()];
                    }

                    if (! $line->kind->isAllocatable()) {
                        throw ReconciliationRuleException::of('line_kind', "سطر {$line->kind->value} لا يدخل تكلفة الخدمة (الضرائب والبنود الأخرى لا تُخصَّص).");
                    }

                    $lineScaled = self::scaledOf((string) $line->amount);

                    if (($lineScaled > 0 && $scaled <= 0) || ($lineScaled < 0 && $scaled >= 0)) {
                        throw ReconciliationRuleException::of('sign', "تخصيص السطر #{$line->id} يجب أن يحمل إشارة السطر نفسها (".ReconciliationRules::format($lineScaled).').');
                    }

                    // The cap is on the SOURCE share (line currency) across all reconciliations.
                    $already = DecimalMath::intFromDb(CostInvoiceAllocation::query()->where('cost_invoice_line_id', $line->id)->selectRaw('COALESCE(SUM(ROUND(COALESCE(source_amount, amount) * 1000000)), 0) AS s')->value('s'));
                    $pending = array_sum(array_map(static fn (array $r): int => $r['line_id'] === $line->id ? $r['source_scaled'] : 0, $evidenceRows));

                    if (abs($already + $pending + $scaled) > abs($lineScaled)) {
                        throw ReconciliationRuleException::of('allocation_limit', 'مجموع تخصيصات السطر #'.$line->id.' ('.ReconciliationRules::format($already + $pending + $scaled).' '.$invoice->currency.') يتجاوز مبلغ السطر ('.ReconciliationRules::format($lineScaled).'). لم يُكتب شيء.');
                    }

                    $evidenceRows[] = ['invoice_id' => $invoice->id, 'line_id' => $line->id, 'source_scaled' => $scaled, 'source_currency' => $invoice->currency, 'scaled' => $convertedScaled, 'fx' => $fx, 'issued_at' => $invoice->issued_at->utc()->format('Y-m-d')];
                    $reconciledScaled += $convertedScaled;
                }
            } elseif ($source === ReconciliationSource::ManualEvidenced) {
                $reconciledScaled = (int) $manualScaled;
            }

            $now = CarbonImmutable::now();
            $previous = $scope->current_reconciliation_id;

            $reconciliation = CostReconciliation::query()->create([
                'scope_id' => $scope->id,
                'component' => $component->value,
                'counterparty_key' => $counterparty,
                'period_start' => $start,
                'period_end' => $end,
                'currency' => $currency,
                'source' => $source->value,
                'reconciled_amount' => ReconciliationRules::format($reconciledScaled),
                'calculated_known_amount' => $snapshot->knownAmount(),
                'calculated_priced_rows' => $snapshot->pricedRows,
                'unpriced_rows' => $snapshot->unpricedRows,
                'currency_mismatch_rows' => $snapshot->currencyMismatchRows,
                'ledger_max_event_id' => $snapshot->maxEventId,
                'cost_coverage_status' => $snapshot->coverage->value,
                'captured_at' => $snapshot->capturedAt,
                'snapshot_hash' => $snapshot->hash(),
                'supersedes_id' => $previous,
                'reason_code' => $reason,
                'evidence_ref' => $evidence,
                'actor_ref' => FinanceAuthorization::actorRef(),
                'created_at' => $now,
            ]);

            foreach ($evidenceRows as $row) {
                CostInvoiceAllocation::query()->create([
                    'cost_invoice_id' => $row['invoice_id'], 'cost_invoice_line_id' => $row['line_id'], 'cost_reconciliation_id' => $reconciliation->id,
                    'amount' => ReconciliationRules::format($row['scaled']), 'currency' => $currency,
                    'source_amount' => ReconciliationRules::format($row['source_scaled']), 'source_currency' => $row['source_currency'],
                    'actor_ref' => FinanceAuthorization::actorRef(), 'created_at' => $now,
                ] + ($row['fx'] ?? []));
            }

            $scope->forceFill(['current_reconciliation_id' => $reconciliation->id, 'version' => $scope->version + 1, 'updated_by_ref' => FinanceAuthorization::actorRef()])->save();

            $this->audit->record(AuditActions::CostReconciled, $scope, [
                'current_reconciliation_id' => ['from' => $previous, 'to' => $reconciliation->id],
            ], [
                'component' => $component->value, 'counterparty_key' => $counterparty, 'month' => $start->format('Y-m'), 'currency' => $currency,
                'source' => $source->value, 'reconciled_amount' => (string) $reconciliation->reconciled_amount,
                'calculated_known_amount' => $snapshot->knownAmount(), 'cost_coverage_status' => $snapshot->coverage->value,
                'unpriced_rows' => $snapshot->unpricedRows, 'snapshot_hash' => $snapshot->hash(),
                'typed_confirmation' => $source === ReconciliationSource::ConfirmedZero ? self::ZERO_CONFIRMATION : null,
                'reason_code' => $reason, 'evidence_ref' => $evidence, 'evidence_lines' => array_column($evidenceRows, 'line_id'),
                'evidence_fx' => array_values(array_map(static fn (array $r): array => [
                    'line_id' => $r['line_id'], 'invoice_issued_at' => $r['issued_at'], 'source_amount' => ReconciliationRules::format($r['source_scaled']), 'source_currency' => $r['source_currency'],
                    'converted_amount' => ReconciliationRules::format($r['scaled']), 'currency' => $currency,
                ] + ($r['fx'] ?? ['fx_rate_id' => null, 'fx_rate_date' => null, 'fx_direction' => 'NATIVE']), array_filter($evidenceRows, static fn (array $r): bool => $r['fx'] !== null))),
            ]);

            return $reconciliation;
        });
    }

    /**
     * Append a signed correction to the scope's CURRENT reconciliation.
     *
     * Idempotency (E5.2b, durable): every NEW adjustment requires a caller-
     * owned opaque key (the column is nullable only for rows written before
     * E5.2b). Same key + same canonical facts (reconciliation, amount,
     * currency, reason, evidence) ⇒ the existing adjustment, no new row, no
     * new audit, the base never touched; any different fact ⇒
     * ReconciliationConflictException, nothing written. The unique index is
     * the authority: the insert runs in a savepoint, a unique violation is
     * caught, the existing row is fetched by key and compared.
     *
     * @throws ReconciliationRuleException|StaleReconciliationException|ReconciliationConflictException
     */
    public function adjust(int $reconciliationId, string $amount, string $reasonCode, string $evidenceRef, string $idempotencyKey): CostAdjustment
    {
        FinanceAuthorization::assertCan(Permission::FinanceReconcile);
        $scaled = ReconciliationRules::signedAmount($amount, 'amount');
        $reason = ReconciliationRules::requiredRef($reasonCode, 32, 'reason_code');
        $evidence = ReconciliationRules::requiredRef($evidenceRef, 191, 'evidence_ref');
        $key = ReconciliationRules::idempotencyKey($idempotencyKey);

        return DB::transaction(function () use ($reconciliationId, $scaled, $reason, $evidence, $key): CostAdjustment {
            $reconciliation = CostReconciliation::query()->whereKey($reconciliationId)->first()
                ?? throw ReconciliationRuleException::of('reconciliation', 'التسوية غير موجودة.');
            $scope = CostReconciliationScope::query()->whereKey($reconciliation->scope_id)->lockForUpdate()->firstOrFail();

            $facts = [
                'cost_reconciliation_id' => $reconciliation->id, 'amount' => ReconciliationRules::format($scaled), 'currency' => $reconciliation->currency,
                'reason_code' => $reason, 'evidence_ref' => $evidence, 'idempotency_key' => $key,
            ];

            // Replay / conflict seen under the scope lock (a committed same-key row); the unique index below is the authority.
            $existing = CostAdjustment::query()->where('idempotency_key', $key)->first();

            if ($existing !== null) {
                return self::sameAdjustment($existing, $facts) ? $existing : throw self::adjustmentConflict($key, $existing->id);
            }

            if ($scope->current_reconciliation_id !== $reconciliation->id) {
                throw new StaleReconciliationException("التسوية #{$reconciliation->id} لم تعد التسوية الحالية لهذا النطاق (الحالية #{$scope->current_reconciliation_id}). لم يُكتب شيء.");
            }

            $now = CarbonImmutable::now();

            try {
                return DB::transaction(function () use ($facts, $scope, $reconciliation, $reason, $evidence, $now): CostAdjustment { // savepoint: row + its audit, or neither
                    $adjustment = CostAdjustment::query()->create($facts + ['actor_ref' => FinanceAuthorization::actorRef(), 'created_at' => $now]);

                    $this->audit->record(AuditActions::CostAdjusted, $scope, [
                        'adjustment' => ['from' => null, 'to' => ['id' => $adjustment->id, 'amount' => (string) $adjustment->amount, 'currency' => $adjustment->currency]],
                    ], ['reconciliation_id' => $reconciliation->id, 'base_reconciled_amount' => (string) $reconciliation->reconciled_amount, 'reason_code' => $reason, 'evidence_ref' => $evidence, 'idempotency_key' => $adjustment->idempotency_key]);

                    return $adjustment;
                });
            } catch (UniqueConstraintViolationException) {
                $existing = CostAdjustment::query()->where('idempotency_key', $key)->first();

                if ($existing === null || ! self::sameAdjustment($existing, $facts)) {
                    throw self::adjustmentConflict($key, $existing?->id);
                }

                return $existing;
            }
        });
    }

    /**
     * Canonical facts of an adjustment: the reconciliation it corrects, signed amount, currency, reason, evidence.
     *
     * @param  array<string, mixed>  $facts
     */
    private static function sameAdjustment(CostAdjustment $existing, array $facts): bool
    {
        return $existing->cost_reconciliation_id === $facts['cost_reconciliation_id']
            && (string) $existing->amount === $facts['amount']
            && $existing->currency === $facts['currency']
            && $existing->reason_code === $facts['reason_code']
            && $existing->evidence_ref === $facts['evidence_ref'];
    }

    private static function adjustmentConflict(string $key, ?int $existingId): ReconciliationConflictException
    {
        return new ReconciliationConflictException("مفتاح idempotency [{$key}] مستخدم لتعديل بحقائق مختلفة".($existingId === null ? '' : " (#{$existingId})").'. لم يُكتب شيء.');
    }

    /**
     * The scope row is created on first use (savepoint-safe against a
     * concurrent creator) and then locked FOR UPDATE.
     */
    private function lockScope(CostComponent $component, string $counterparty, CarbonImmutable $start, CarbonImmutable $end, string $currency): CostReconciliationScope
    {
        $find = static fn () => CostReconciliationScope::query()
            ->where('component', $component->value)->where('counterparty_key', $counterparty)
            ->where('period_start', $start->utc()->format('Y-m-d H:i:s'))->where('currency', $currency)
            ->lockForUpdate()->first();

        $scope = $find();

        if ($scope === null) {
            try {
                DB::transaction(static fn () => CostReconciliationScope::query()->create([ // savepoint
                    'component' => $component->value, 'counterparty_key' => $counterparty, 'period_start' => $start, 'period_end' => $end, 'currency' => $currency,
                    'current_reconciliation_id' => null, 'version' => 0, 'updated_by_ref' => null,
                ]));
            } catch (UniqueConstraintViolationException) {
                // A concurrent reconciler created it first — fall through to the lock.
            }

            $scope = $find() ?? throw new StaleReconciliationException('تعذّر إنشاء نطاق التسوية؛ أعد المحاولة.');
        }

        ReconciliationRules::assertCalendarMonth($scope->period_start->toImmutable(), $scope->period_end->toImmutable());

        return $scope;
    }

    public static function scaledOf(string $amount): int
    {
        return ReconciliationRules::signedAmount($amount, 'amount', allowZero: true);
    }
}
