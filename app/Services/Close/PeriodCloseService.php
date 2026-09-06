<?php

declare(strict_types=1);

namespace App\Services\Close;

use App\Enums\CloseInputType;
use App\Enums\PeriodCloseStatus;
use App\Exceptions\Close\CloseBlockedException;
use App\Exceptions\Close\CloseRuleException;
use App\Exceptions\Close\StaleCloseException;
use App\Exceptions\Reconciliation\ReconciliationRuleException;
use App\Models\FinancePeriodClose;
use App\Models\FinancePeriodCloseInput;
use App\Models\FinancePeriodCloseScope;
use App\Services\Audit\AuditLogger;
use App\Services\Fx\ReportingCurrencyService;
use App\Support\Audit\AuditActions;
use App\Support\Payments\FinanceAuthorization;
use App\Support\Rbac\Permission;
use App\Support\Reconciliation\ReconciliationRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Period close (Phase E4) — super_admin only, typed confirmation, append-only:
 *
 *  close(): find-or-create the (month, reporting currency) scope → FOR UPDATE
 *  → expected pointer (stale ⇒ refused) → state must be open → re-evaluate
 *  preflight UNDER THE LOCK and refuse on any blocking condition → insert the
 *  close record with the frozen figures, conditions, canonical snapshot and
 *  its hash → generate the input rows from the SAME snapshot → move the
 *  pointer to `closed` → audit. One transaction: an audit failure leaves
 *  neither the close nor its input rows.
 *  reopen(): lock → pointer must be the close being reopened → insert a
 *  `reopened` record (reason + evidence mandatory) that references it → state
 *  `open` → audit. The old close and its inputs are never touched; the next
 *  close is a new revision chained through previous_close_id.
 *  Idempotency: the same key returns the same close; a different key on an
 *  already-closed scope is ALREADY_CLOSED. Nothing here writes to any other
 *  finance table, and nothing ever recomputes a historical close.
 */
final class PeriodCloseService
{
    public function __construct(private readonly AuditLogger $audit, private readonly ClosePreflight $preflight, private readonly ReportingCurrencyService $reporting) {}

    public function close(string $month, ?int $expectedCurrentCloseId, string $idempotencyKey, string $typedConfirmation): FinancePeriodClose
    {
        FinanceAuthorization::assertCan(Permission::FinanceClosePeriod);
        [$start, $end] = ReconciliationRules::month($month);
        $monthKey = $start->format('Y-m');
        $key = trim($idempotencyKey);

        if ($key === '' || mb_strlen($key) > 191) {
            throw CloseRuleException::of('idempotency_key', 'مفتاح idempotency إلزامي (حتى 191 حرفًا).');
        }

        if ($typedConfirmation !== "CLOSE {$monthKey}") {
            throw CloseRuleException::of('typed_confirmation', "اكتب CLOSE {$monthKey} حرفيًا لتأكيد الإقفال.");
        }

        $target = $this->reporting->current();

        return DB::transaction(function () use ($start, $end, $monthKey, $key, $target, $expectedCurrentCloseId, $typedConfirmation): FinancePeriodClose {
            $existing = FinancePeriodClose::query()->where('idempotency_key', $key)->first();

            if ($existing !== null) {
                if ($existing->status !== PeriodCloseStatus::Closed || $existing->month() !== $monthKey || $existing->reporting_currency !== $target) {
                    throw CloseRuleException::of('idempotency_conflict', "مفتاح idempotency [{$key}] مستخدم لسجل إقفال مختلف (#{$existing->id}).");
                }

                return $existing; // same request replayed
            }

            $scope = $this->lockScope($start, $end, $target);

            if ($scope->current_close_id !== $expectedCurrentCloseId) {
                throw new StaleCloseException('حالة إقفال هذا الشهر تغيّرت (المتوقع '.($expectedCurrentCloseId ?? 'none').'، الحالي '.($scope->current_close_id ?? 'none').'). حدّث وأعد المحاولة. لم يُكتب شيء.');
            }

            if ($scope->isClosed()) {
                throw CloseRuleException::of('ALREADY_CLOSED', "الشهر {$monthKey} مقفل بالفعل (#{$scope->current_close_id})؛ أعد فتحه أولًا لإنشاء مراجعة جديدة.");
            }

            $evaluation = $this->preflight->evaluate($monthKey, $target);

            if (! $evaluation->canClose()) {
                throw new CloseBlockedException($evaluation->blocking());
            }

            $previous = $scope->current_close_id;
            $revision = (int) FinancePeriodClose::query()->where('scope_id', $scope->id)->where('status', PeriodCloseStatus::Closed->value)->max('revision') + 1;
            $now = CarbonImmutable::now();

            $close = FinancePeriodClose::query()->create([
                'scope_id' => $scope->id, 'period_start' => $start, 'period_end' => $end, 'reporting_currency' => $target,
                'status' => PeriodCloseStatus::Closed->value, 'revision' => $revision, 'previous_close_id' => $previous, 'reopened_close_id' => null,
                'idempotency_key' => $key,
                'gross_cash_collected' => $evaluation->metrics['gross_cash_collected'], 'refunds' => $evaluation->metrics['refunds'], 'net_cash' => $evaluation->metrics['net_cash'],
                'gateway_fees' => $evaluation->metrics['gateway_fees'], 'net_cash_after_gateway_fees' => $evaluation->metrics['net_cash_after_gateway_fees'],
                'reconciled_service_cost' => $evaluation->metrics['reconciled_service_cost'], 'reconciled_cash_contribution' => $evaluation->metrics['reconciled_cash_contribution'],
                'conditions' => $evaluation->conditions, 'inputs_snapshot' => $evaluation->snapshot, 'input_hash' => $evaluation->inputHash,
                'typed_confirmation' => $typedConfirmation, 'reason_code' => null, 'evidence_ref' => null,
                'closed_at' => $now, 'actor_ref' => FinanceAuthorization::actorRef(), 'created_at' => $now,
            ]);

            self::projectInputs($close, $evaluation->snapshot, $now);

            $scope->forceFill(['state' => 'closed', 'current_close_id' => $close->id, 'version' => $scope->version + 1, 'updated_by_ref' => FinanceAuthorization::actorRef()])->save();

            $this->audit->record(AuditActions::FinancePeriodClosed, $scope, [
                'current_close_id' => ['from' => $previous, 'to' => $close->id],
                'state' => ['from' => 'open', 'to' => 'closed'],
            ], [
                'month' => $monthKey, 'reporting_currency' => $target, 'revision' => $revision, 'input_hash' => $evaluation->inputHash,
                'typed_confirmation' => $typedConfirmation, 'metrics' => $evaluation->metrics,
                'informational_conditions' => array_values(array_map(static fn (array $c): string => $c['code'].' ('.$c['detail'].')', array_filter($evaluation->conditions, static fn (array $c): bool => ! $c['blocking']))),
            ]);

            return $close;
        });
    }

    public function reopen(int $closeId, ?int $expectedCurrentCloseId, string $reasonCode, string $evidenceRef, string $typedConfirmation): FinancePeriodClose
    {
        FinanceAuthorization::assertCan(Permission::FinanceClosePeriod);

        try {
            $reason = ReconciliationRules::requiredRef($reasonCode, 32, 'reason_code');
            $evidence = ReconciliationRules::requiredRef($evidenceRef, 191, 'evidence_ref');
        } catch (ReconciliationRuleException $e) {
            throw CloseRuleException::of($e->rule, $e->getMessage());
        }

        return DB::transaction(function () use ($closeId, $expectedCurrentCloseId, $reason, $evidence, $typedConfirmation): FinancePeriodClose {
            $close = FinancePeriodClose::query()->whereKey($closeId)->first() ?? throw CloseRuleException::of('close', 'سجل الإقفال غير موجود.');
            $scope = FinancePeriodCloseScope::query()->whereKey($close->scope_id)->lockForUpdate()->firstOrFail();
            $monthKey = $scope->month();

            if ($typedConfirmation !== "REOPEN {$monthKey}") {
                throw CloseRuleException::of('typed_confirmation', "اكتب REOPEN {$monthKey} حرفيًا لتأكيد إعادة الفتح.");
            }

            if ($scope->current_close_id !== $expectedCurrentCloseId) {
                throw new StaleCloseException('حالة إقفال هذا الشهر تغيّرت (المتوقع '.($expectedCurrentCloseId ?? 'none').'، الحالي '.($scope->current_close_id ?? 'none').'). لم يُكتب شيء.');
            }

            if (! $scope->isClosed() || $scope->current_close_id !== $close->id || $close->status !== PeriodCloseStatus::Closed) {
                throw CloseRuleException::of('NOT_CLOSED', "السجل #{$close->id} ليس الإقفال الحالي للشهر {$monthKey}.");
            }

            $now = CarbonImmutable::now();
            $record = FinancePeriodClose::query()->create([
                'scope_id' => $scope->id, 'period_start' => $close->period_start, 'period_end' => $close->period_end, 'reporting_currency' => $close->reporting_currency,
                'status' => PeriodCloseStatus::Reopened->value, 'revision' => $close->revision, 'previous_close_id' => $close->id, 'reopened_close_id' => $close->id,
                'idempotency_key' => 'reopen:'.$close->id.':'.$now->format('YmdHisu'),
                'conditions' => [], 'inputs_snapshot' => ['reopened_close_id' => $close->id, 'reopened_input_hash' => $close->input_hash], 'input_hash' => null,
                'typed_confirmation' => $typedConfirmation, 'reason_code' => $reason, 'evidence_ref' => $evidence,
                'closed_at' => $now, 'actor_ref' => FinanceAuthorization::actorRef(), 'created_at' => $now,
            ]);

            $scope->forceFill(['state' => 'open', 'current_close_id' => $record->id, 'version' => $scope->version + 1, 'updated_by_ref' => FinanceAuthorization::actorRef()])->save();

            $this->audit->record(AuditActions::FinancePeriodReopened, $scope, [
                'current_close_id' => ['from' => $close->id, 'to' => $record->id],
                'state' => ['from' => 'closed', 'to' => 'open'],
            ], ['month' => $monthKey, 'reopened_close_id' => $close->id, 'reopened_input_hash' => $close->input_hash, 'reason_code' => $reason, 'evidence_ref' => $evidence, 'typed_confirmation' => $typedConfirmation]);

            return $record;
        });
    }

    /**
     * The drill-down rows are derived from the canonical snapshot ONLY —
     * the same array the hash was computed from — inside the close transaction.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public static function projectInputs(FinancePeriodClose $close, array $snapshot, CarbonImmutable $now): void
    {
        $sections = ['payments' => CloseInputType::Payment, 'refunds' => CloseInputType::Refund, 'gateway_fees' => CloseInputType::GatewayFee, 'reconciliations' => CloseInputType::Reconciliation, 'adjustments' => CloseInputType::Adjustment];

        foreach ($sections as $section => $type) {
            foreach ($snapshot[$section] ?? [] as $line) {
                FinancePeriodCloseInput::query()->create([
                    'close_id' => $close->id, 'input_type' => $type->value, 'input_id' => $line['id'],
                    'amount' => $line['amount'] ?? '0', 'currency' => $line['currency'], 'scale' => $line['scale'],
                    'reporting_amount' => $line['reporting_amount'], 'reporting_currency' => $snapshot['reporting_currency'], 'status' => $line['status'],
                    'fx_conversion_id' => $line['fx_conversion_id'], 'fx_rate_id' => $line['fx_rate_id'], 'fx_rate_snapshot' => $line['fx_rate_snapshot'], 'fx_direction' => $line['fx_direction'],
                    'flags' => array_values(array_filter([
                        isset($line['component']) ? 'component:'.$line['component'] : null,
                        isset($line['counterparty_key']) ? 'counterparty:'.$line['counterparty_key'] : null,
                        isset($line['source']) ? 'source:'.$line['source'] : null,
                        isset($line['reconciliation_id']) ? 'reconciliation:'.$line['reconciliation_id'] : null,
                        isset($line['payment_id']) && $type === CloseInputType::GatewayFee ? 'payment:'.$line['payment_id'] : null,
                        ...($line['flags'] ?? []),
                    ])),
                    'created_at' => $now,
                ]);
            }
        }
    }

    /** Current-close drift: the live evaluation hashes differently from the frozen close (informational, never mutates). */
    public function drift(FinancePeriodClose $close): bool
    {
        if ($close->status !== PeriodCloseStatus::Closed) {
            return false;
        }

        return ! hash_equals((string) $close->input_hash, $this->preflight->evaluate($close->month(), $close->reporting_currency)->inputHash);
    }

    private function lockScope(CarbonImmutable $start, CarbonImmutable $end, string $target): FinancePeriodCloseScope
    {
        $find = static fn () => FinancePeriodCloseScope::query()->where('period_start', $start->format('Y-m-d H:i:s'))->where('reporting_currency', $target)->lockForUpdate()->first();
        $scope = $find();

        if ($scope === null) {
            try {
                DB::transaction(static fn () => FinancePeriodCloseScope::query()->create(['period_start' => $start, 'period_end' => $end, 'reporting_currency' => $target, 'state' => 'open', 'current_close_id' => null, 'version' => 0]));
            } catch (UniqueConstraintViolationException) {
                // created concurrently
            }

            $scope = $find() ?? throw new StaleCloseException('تعذّر إنشاء نطاق الإقفال؛ أعد المحاولة.');
        }

        return $scope;
    }
}
