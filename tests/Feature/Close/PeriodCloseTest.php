<?php

declare(strict_types=1);

use App\Enums\PeriodCloseStatus;
use App\Exceptions\Close\CloseBlockedException;
use App\Exceptions\Close\StaleCloseException;
use App\Exceptions\Payments\ImmutableFinancialRecordException;
use App\Models\AuditLog;
use App\Models\CostReconciliation;
use App\Models\CustomerPayment;
use App\Models\FinancePeriodClose;
use App\Models\FinancePeriodCloseInput;
use App\Models\FinancePeriodCloseScope;
use App\Services\Audit\AuditLogger;
use App\Services\Close\ClosePreflight;
use App\Services\Close\PeriodCloseService;
use App\Services\Fx\ReportingCurrencyService;
use App\Services\Reconciliation\CostReconciliationService;
use App\Support\Audit\AuditActions;
use App\Support\Fx\FxMath;
use App\Support\Rbac\Role;
use App\Support\Security\SecretRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Phase E4 — close(): frozen figures, canonical snapshot, hash from the
 * canonical JSON only, input rows generated from the same snapshot (never a
 * second source of truth), append-only, revisioned, idempotent, stale-safe,
 * super_admin only with typed confirmation, atomic with audit, writing
 * nothing to any other finance table. reopen(): a new record, the old close
 * untouched. DRIFT SINCE CLOSE is informational.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));
});

function reopen(FinancePeriodClose $close, ?int $expected = null): FinancePeriodClose
{
    return app(PeriodCloseService::class)->reopen($close->id, $expected ?? $close->id, 'restatement', 'memo:1', 'REOPEN '.$close->month());
}

it('closes a complete month: frozen figures, conditions, canonical snapshot, hash, input rows from the same snapshot, pointer, audit', function () {
    $fx = closableMonth();
    $evaluation = app(ClosePreflight::class)->evaluate('2026-08');

    $close = closeMonth('2026-08', null, 'k-aug-1');
    $scope = FinancePeriodCloseScope::query()->firstOrFail();

    expect($close->status)->toBe(PeriodCloseStatus::Closed)->and($close->revision)->toBe(1)->and($close->previous_close_id)->toBeNull()
        ->and($close->reporting_currency)->toBe('USD')->and($close->typed_confirmation)->toBe('CLOSE 2026-08')->and($close->actor_ref)->toBe('console')
        ->and((string) $close->gross_cash_collected)->toBe('200.000000')->and((string) $close->refunds)->toBe('10.000000')->and((string) $close->net_cash)->toBe('190.000000')
        ->and((string) $close->gateway_fees)->toBe('4.000000')->and((string) $close->net_cash_after_gateway_fees)->toBe('186.000000')
        ->and((string) $close->reconciled_service_cost)->toBe('55.000000')->and((string) $close->reconciled_cash_contribution)->toBe('131.000000')
        ->and($close->input_hash)->toBe($evaluation->inputHash)
        ->and($close->inputs_snapshot)->toBe($evaluation->snapshot)
        ->and(ClosePreflight::hash($close->inputs_snapshot))->toBe($close->input_hash) // hash derives from the canonical JSON only
        ->and($scope->state)->toBe('closed')->and($scope->current_close_id)->toBe($close->id)->and($scope->version)->toBe(1)
        ->and(AuditLog::where('action', AuditActions::FinancePeriodClosed)->count())->toBe(1)
        ->and(AuditLog::where('action', AuditActions::FinancePeriodClosed)->first()->metadata['context']['input_hash'])->toBe($close->input_hash);

    // Input rows ↔ canonical snapshot: one row per snapshot line, same amounts / statuses / FX facts, nothing extra.
    $rows = FinancePeriodCloseInput::query()->where('close_id', $close->id)->get();
    $expected = [];
    foreach (['payments' => 'payment', 'refunds' => 'refund', 'gateway_fees' => 'gateway_fee', 'reconciliations' => 'reconciliation', 'adjustments' => 'adjustment'] as $section => $type) {
        foreach ($close->inputs_snapshot[$section] as $line) {
            $expected[] = [$type, $line['id'], $line['amount'] === null ? '0.000000' : FxMath::formatAtScale($line['amount'], 6), $line['reporting_amount'] === null ? null : FxMath::formatAtScale($line['reporting_amount'], 6), $line['status'], $line['fx_rate_id']];
        }
    }
    $actual = $rows->map(fn ($r) => [$r->input_type->value, $r->input_id, (string) $r->amount, $r->reporting_amount === null ? null : (string) $r->reporting_amount, $r->status, $r->fx_rate_id])->all();
    sort($expected);
    sort($actual);
    expect($actual)->toBe($expected)->and($rows)->toHaveCount(2 + 1 + 2 + 3 + 1);

    $feeRow = $rows->first(fn ($r) => $r->input_type->value === 'gateway_fee' && $r->input_id === $fx['ils']->id);
    expect($feeRow->fx_rate_id)->toBe($fx['rate']->id)->and($feeRow->fx_conversion_id)->toBe($fx['conversion']->id)->and($feeRow->fx_direction)->toBe('inverse')
        ->and((string) $feeRow->reporting_amount)->toBe('1.000000')->and($feeRow->flags)->toContain('payment:'.$fx['ils']->id);
});

it('never edits a close or its input rows, and the input rows are never an independent source: they only ever match the snapshot', function () {
    closableMonth();
    $close = closeMonth();
    $row = FinancePeriodCloseInput::query()->where('close_id', $close->id)->firstOrFail();

    expect(fn () => $close->forceFill(['reconciled_cash_contribution' => '1'])->save())->toThrow(ImmutableFinancialRecordException::class)
        ->and(fn () => $close->forceFill(['inputs_snapshot' => []])->save())->toThrow(ImmutableFinancialRecordException::class)
        ->and(fn () => $close->delete())->toThrow(ImmutableFinancialRecordException::class)
        ->and(fn () => $row->forceFill(['amount' => '1'])->save())->toThrow(ImmutableFinancialRecordException::class)
        ->and(fn () => $row->delete())->toThrow(ImmutableFinancialRecordException::class)
        ->and(fn () => FinancePeriodCloseScope::query()->firstOrFail()->forceFill(['reporting_currency' => 'EUR'])->save())->toThrow(ImmutableFinancialRecordException::class)
        ->and(FinancePeriodCloseInput::query()->where('close_id', $close->id)->count())->toBe(9);

    // Source level: rows are generated from the snapshot array passed to projectInputs, never from a query.
    $src = php_strip_whitespace(app_path('Services/Close/PeriodCloseService.php'));
    expect(preg_match('/projectInputs\(\$close, \$evaluation->snapshot/', $src))->toBe(1)
        ->and(preg_match('/FinancePeriodCloseInput::query\(\)->(update|delete|where)/', $src))->toBe(0);
});

it('refuses a blocked month, a wrong typed confirmation, a stale pointer, a second close, and an idempotency conflict — writing nothing; the same key replays the same close', function () {
    closableMonth();
    e1Payment(billingSubscriber(), ['amount' => '1.00', 'currency' => 'USD', 'receivedAt' => CarbonImmutable::parse('2026-08-25', 'UTC')]); // fee unknown
    $service = app(PeriodCloseService::class);

    expect(fn () => closeMonth())->toThrow(CloseBlockedException::class, 'FEES_INCOMPLETE')
        ->and(FinancePeriodClose::count())->toBe(0)->and(FinancePeriodCloseScope::count())->toBe(0)->and(AuditLog::where('action', AuditActions::FinancePeriodClosed)->count())->toBe(0);

    // Make it closable again by using a month with the offending payment excluded? Simpler: give the payment a fee is impossible (immutable) — so build a clean month instead.
    DB::table('customer_payment_events')->where('customer_payment_id', CustomerPayment::query()->max('id'))->delete();
    DB::table('customer_payments')->where('id', CustomerPayment::query()->max('id'))->delete(); // test-only cleanup of the fixture row

    expect(closeRule(fn () => $service->close('2026-08', null, 'k1', 'close 2026-08')))->toBe('typed_confirmation')
        ->and(closeRule(fn () => $service->close('2026-08', null, 'k1', 'CLOSE 2026-07')))->toBe('typed_confirmation')
        ->and(closeRule(fn () => $service->close('2026-08', null, ' ', 'CLOSE 2026-08')))->toBe('idempotency_key')
        ->and(fn () => $service->close('2026-08', 999, 'k1', 'CLOSE 2026-08'))->toThrow(StaleCloseException::class)
        ->and(FinancePeriodClose::count())->toBe(0);

    $close = $service->close('2026-08', null, 'k1', 'CLOSE 2026-08');
    $again = $service->close('2026-08', null, 'k1', 'CLOSE 2026-08'); // replay: same row, no second close, no second audit
    expect($again->id)->toBe($close->id)->and(FinancePeriodClose::count())->toBe(1)->and(AuditLog::where('action', AuditActions::FinancePeriodClosed)->count())->toBe(1)
        ->and(closeRule(fn () => $service->close('2026-08', $close->id, 'k2', 'CLOSE 2026-08')))->toBe('ALREADY_CLOSED')
        ->and(fn () => $service->close('2026-08', null, 'k2', 'CLOSE 2026-08'))->toThrow(StaleCloseException::class)
        ->and(closeRule(fn () => $service->close('2026-07', null, 'k1', 'CLOSE 2026-07')))->toBe('idempotency_conflict')
        ->and(FinancePeriodClose::count())->toBe(1);
});

it('reopens with a new record (reason + evidence + typed), leaves the old close and its inputs untouched, and the next close is revision 2 chained through previous_close_id', function () {
    closableMonth();
    $service = app(PeriodCloseService::class);
    $first = closeMonth('2026-08', null, 'k1');
    $firstSnapshot = $first->inputs_snapshot;
    $firstRows = FinancePeriodCloseInput::query()->where('close_id', $first->id)->get()->map(fn ($r) => $r->getAttributes())->all();

    expect(closeRule(fn () => $service->reopen($first->id, $first->id, 'x', 'y', 'reopen 2026-08')))->toBe('typed_confirmation')
        ->and(closeRule(fn () => $service->reopen($first->id, $first->id, '', 'y', 'REOPEN 2026-08')))->toBe('reason_code')
        ->and(closeRule(fn () => $service->reopen($first->id, $first->id, 'x', '', 'REOPEN 2026-08')))->toBe('evidence_ref')
        ->and(fn () => $service->reopen($first->id, null, 'x', 'y', 'REOPEN 2026-08'))->toThrow(StaleCloseException::class)
        ->and(closeRule(fn () => $service->reopen(999, $first->id, 'x', 'y', 'REOPEN 2026-08')))->toBe('close');

    $reopen = reopen($first);
    $scope = FinancePeriodCloseScope::query()->firstOrFail();
    expect($reopen->status)->toBe(PeriodCloseStatus::Reopened)->and($reopen->reopened_close_id)->toBe($first->id)->and($reopen->previous_close_id)->toBe($first->id)
        ->and($reopen->reason_code)->toBe('restatement')->and($reopen->evidence_ref)->toBe('memo:1')->and($reopen->input_hash)->toBeNull()->and($reopen->reconciled_cash_contribution)->toBeNull()
        ->and($scope->state)->toBe('open')->and($scope->current_close_id)->toBe($reopen->id)->and($scope->version)->toBe(2)
        ->and(AuditLog::where('action', AuditActions::FinancePeriodReopened)->count())->toBe(1)
        ->and($first->fresh()->inputs_snapshot)->toBe($firstSnapshot)->and($first->fresh()->status)->toBe(PeriodCloseStatus::Closed)->and((string) $first->fresh()->reconciled_cash_contribution)->toBe('131.000000')
        ->and(FinancePeriodCloseInput::query()->where('close_id', $first->id)->get()->map(fn ($r) => $r->getAttributes())->all())->toBe($firstRows)
        ->and(closeRule(fn () => reopen($first, $reopen->id)))->toBe('NOT_CLOSED'); // already reopened

    // Live data may change (a late adjustment); the next close is revision 2 with new figures; revision 1 stays.
    app(CostReconciliationService::class)->adjust(CostReconciliation::query()->where('component', 'provider')->firstOrFail()->id, '-1.000000', 'credit', 'cn:2');
    $second = closeMonth('2026-08', $reopen->id, 'k2');
    expect($second->revision)->toBe(2)->and($second->previous_close_id)->toBe($reopen->id)->and((string) $second->reconciled_cash_contribution)->toBe('132.000000')
        ->and($second->input_hash)->not->toBe($first->input_hash)
        ->and((string) $first->fresh()->reconciled_cash_contribution)->toBe('131.000000')
        ->and(FinancePeriodClose::count())->toBe(3)->and($scope->fresh()->state)->toBe('closed')->and($scope->fresh()->current_close_id)->toBe($second->id);
});

it('flags DRIFT SINCE CLOSE when live data changes after a close, without mutating the close; a reporting-currency change never recomputes it', function () {
    closableMonth();
    $service = app(PeriodCloseService::class);
    $close = closeMonth();
    expect($service->drift($close))->toBeFalse();

    app(CostReconciliationService::class)->adjust(CostReconciliation::query()->where('component', 'provider')->firstOrFail()->id, '-1.000000', 'credit', 'cn:2');
    expect($service->drift($close->fresh()))->toBeTrue()->and($close->fresh()->input_hash)->toBe($close->input_hash)->and((string) $close->fresh()->reconciled_cash_contribution)->toBe('131.000000');

    app(ReportingCurrencyService::class)->change('ILS', 'ILS');
    expect((string) $close->fresh()->reconciled_cash_contribution)->toBe('131.000000')->and($close->fresh()->reporting_currency)->toBe('USD')
        ->and(FinancePeriodClose::count())->toBe(1)->and(FinancePeriodCloseScope::query()->firstOrFail()->reporting_currency)->toBe('USD');
});

it('is super_admin only with authorization inside the service; finance can evaluate but not close or reopen', function () {
    closableMonth();
    $service = app(PeriodCloseService::class);

    foreach ([userWithRole(Role::Finance), userWithRole(Role::Operations)] as $user) {
        $this->actingAs($user);
        expect(fn () => $service->close('2026-08', null, 'k-'.$user->id, 'CLOSE 2026-08'))->toThrow(AuthorizationException::class);
    }
    expect(FinancePeriodClose::count())->toBe(0)->and(app(ClosePreflight::class)->evaluate('2026-08')->canClose())->toBeTrue();

    $this->actingAs(userWithRole(Role::SuperAdmin));
    $close = $service->close('2026-08', null, 'k-super', 'CLOSE 2026-08');
    expect($close->actor_ref)->toStartWith('user:');

    $this->actingAs(userWithRole(Role::Finance));
    expect(fn () => $service->reopen($close->id, $close->id, 'x', 'y', 'REOPEN 2026-08'))->toThrow(AuthorizationException::class)
        ->and(FinancePeriodClose::count())->toBe(1);
});

it('is atomic with the audit entry (no close, no input rows, no scope change) and writes nothing to any other finance table', function () {
    closableMonth();

    $writes = [];
    DB::listen(function (QueryExecuted $q) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete)\b/i', $q->sql) === 1 && preg_match('/finance_period_close|audit_logs/', $q->sql) !== 1) {
            $writes[] = $q->sql;
        }
    });
    $close = closeMonth();
    reopen($close);
    expect($writes)->toBe([]);

    app()->instance(AuditLogger::class, new class(app(SecretRedactor::class)) extends AuditLogger
    {
        public function record(string $action, ?Model $subject = null, array $changes = [], array $context = []): AuditLog
        {
            throw new RuntimeException('audit store unavailable');
        }
    });
    $reopenId = FinancePeriodCloseScope::query()->firstOrFail()->current_close_id;
    expect(fn () => closeMonth('2026-08', $reopenId, 'k-atomic'))->toThrow(RuntimeException::class);
    expect(FinancePeriodClose::count())->toBe(2)->and(FinancePeriodCloseInput::count())->toBe(9)
        ->and(FinancePeriodCloseScope::query()->firstOrFail()->state)->toBe('open')->and(FinancePeriodCloseScope::query()->firstOrFail()->current_close_id)->toBe($reopenId);
});
