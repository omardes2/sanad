<?php

declare(strict_types=1);

use App\Enums\CustomerPaymentEventType;
use App\Enums\PaymentSource;
use App\Enums\PeriodCloseStatus;
use App\Exceptions\Close\CloseBlockedException;
use App\Exceptions\Close\StaleCloseException;
use App\Exceptions\Payments\ImmutableFinancialRecordException;
use App\Models\AuditLog;
use App\Models\CostReconciliation;
use App\Models\CostReconciliationScope;
use App\Models\CustomerPayment;
use App\Models\FinancePeriodClose;
use App\Models\FinancePeriodCloseInput;
use App\Models\FinancePeriodCloseScope;
use App\Services\Audit\AuditLogger;
use App\Services\Close\ClosePreflight;
use App\Services\Close\PeriodCloseService;
use App\Services\Fx\ReportingCurrencyService;
use App\Services\Payments\CustomerPaymentService;
use App\Services\Reconciliation\CostInvoiceService;
use App\Services\Reconciliation\CostReconciliationService;
use App\Services\Reconciliation\ReconciledCostQuery;
use App\Support\Audit\AuditActions;
use App\Support\Fx\FxMath;
use App\Support\Rbac\Role;
use App\Support\Security\SecretRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

    // Same key + DIFFERENT canonical inputs ⇒ conflict (never a silent replay of the old row); same key + same inputs ⇒ the same row.
    $reopened = reopen($close);
    expect($service->close('2026-08', $reopened->id, 'k1', 'CLOSE 2026-08')->id)->toBe($close->id); // inputs unchanged ⇒ same row, still no second close
    app(CostReconciliationService::class)->adjust(CostReconciliation::query()->where('component', 'provider')->firstOrFail()->id, '-1.000000', 'credit', 'cn:2');
    expect(closeRule(fn () => $service->close('2026-08', $reopened->id, 'k1', 'CLOSE 2026-08')))->toBe('idempotency_conflict')
        ->and(FinancePeriodClose::query()->where('status', 'closed')->count())->toBe(1)->and(AuditLog::where('action', AuditActions::FinancePeriodClosed)->count())->toBe(1)
        ->and(FinancePeriodCloseScope::query()->firstOrFail()->current_close_id)->toBe($reopened->id);
    $v2 = $service->close('2026-08', $reopened->id, 'k1-new', 'CLOSE 2026-08');
    expect($v2->revision)->toBe(2)->and($v2->previous_close_id)->toBe($close->id)->and($service->close('2026-08', $v2->id, 'k1-new', 'CLOSE 2026-08')->id)->toBe($v2->id);
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
    expect($second->revision)->toBe(2)->and($second->previous_close_id)->toBe($first->id)->and((string) $second->reconciled_cash_contribution)->toBe('132.000000')
        ->and($second->input_hash)->not->toBe($first->input_hash)
        ->and((string) $first->fresh()->reconciled_cash_contribution)->toBe('131.000000')
        ->and(FinancePeriodClose::count())->toBe(3)->and($scope->fresh()->state)->toBe('closed')->and($scope->fresh()->current_close_id)->toBe($second->id);

    // Exactly one current-close projection per (calendar month UTC, reporting currency); the append-only history carries no is_current.
    expect(FinancePeriodCloseScope::query()->where('period_start', '2026-08-01 00:00:00')->where('reporting_currency', 'USD')->count())->toBe(1)
        ->and(Schema::getColumnListing('finance_period_closes'))->not->toContain('is_current')
        ->and(fn () => DB::transaction(fn () => DB::table('finance_period_close_scopes')->insert(['period_start' => '2026-08-01 00:00:00', 'period_end' => '2026-09-01 00:00:00', 'reporting_currency' => 'USD', 'state' => 'open', 'version' => 0, 'created_at' => now(), 'updated_at' => now()])))->toThrow(QueryException::class) // savepoint: PostgreSQL-safe
        ->and(FinancePeriodClose::query()->where('scope_id', $scope->id)->orderBy('id')->get()->map(fn ($r) => [$r->id, $r->status->value, $r->revision, $r->previous_close_id, $r->reopened_close_id])->all())
        ->toBe([[$first->id, 'closed', 1, null, null], [$reopen->id, 'reopened', 1, $first->id, $first->id], [$second->id, 'closed', 2, $first->id, null]]);
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

/** Nothing of a close exists: no close row, no input rows, no scope, no pointer, no close audit. */
function expectNothingClosed(): void
{
    expect(FinancePeriodClose::count())->toBe(0)->and(FinancePeriodCloseInput::count())->toBe(0)->and(FinancePeriodCloseScope::count())->toBe(0)
        ->and(AuditLog::where('action', AuditActions::FinancePeriodClosed)->count())->toBe(0);
}

/** A closed month is untouched: same hash, same figure, still closed and still the current pointer; no new close audit. */
function expectCloseUntouched(FinancePeriodClose $close, string $contribution): void
{
    $scope = FinancePeriodCloseScope::query()->whereKey($close->scope_id)->firstOrFail();
    expect($close->fresh()->input_hash)->toBe($close->input_hash)->and((string) $close->fresh()->reconciled_cash_contribution)->toBe($contribution)->and($close->fresh()->status)->toBe(PeriodCloseStatus::Closed)
        ->and($close->fresh()->inputs_snapshot)->toBe($close->inputs_snapshot)
        ->and($scope->state)->toBe('closed')->and($scope->current_close_id)->toBe($close->id)
        ->and(FinancePeriodClose::query()->where('status', 'closed')->count())->toBe($close->revision)
        ->and(AuditLog::where('action', AuditActions::FinancePeriodClosed)->count())->toBe($close->revision);
}

it('ledger moved BEFORE close (LEDGER MOVED SINCE RECONCILIATION) is a hard blocker: preflight detects it, close is refused, nothing is written; a superseding reconciliation makes the month closable', function () {
    $fx = closableMonth();
    expect(app(ClosePreflight::class)->evaluate('2026-08')->canClose())->toBeTrue();

    // A. new usage for the same month/scope after the current reconciliation froze its ledger snapshot
    financeRow(['provider' => 'groq', 'provider_cost' => '1.000000', 'total_cost' => '1.000000', 'occurred_at' => CarbonImmutable::parse('2026-08-20 10:00:00', 'UTC')]);
    $summary = app(ReconciledCostQuery::class)->describe(CostReconciliationScope::query()->whereKey($fx['reconciliation']->scope_id)->firstOrFail());
    $e = app(ClosePreflight::class)->evaluate('2026-08');

    expect($summary->ledgerMoved)->toBeTrue()->and($summary->flags)->toContain('LEDGER MOVED SINCE RECONCILIATION')
        ->and($e->canClose())->toBeFalse()->and($e->blocking())->toBe(['LEDGER_MOVED (reconciliation:'.$fx['reconciliation']->id.')'])
        ->and($e->metrics['reconciled_service_cost'])->toBeNull()->and($e->metrics['reconciled_cash_contribution'])->toBeNull()
        ->and(fn () => closeMonth('2026-08', null, 'k-moved'))->toThrow(CloseBlockedException::class, 'LEDGER_MOVED');
    expectNothingClosed();

    // the finance user records a superseding (fresh-snapshot) reconciliation ⇒ closable; the old adjustment belonged to the superseded reconciliation
    $fresh = e2Reconcile([], ['expectedCurrentReconciliationId' => $fx['reconciliation']->id, 'source' => 'manual_evidenced', 'reconciledAmount' => '60.000000', 'reasonCode' => 'restated', 'evidenceRef' => 'stmt:aug']);
    $e = app(ClosePreflight::class)->evaluate('2026-08');
    expect($e->canClose())->toBeTrue()->and($e->metrics['reconciled_service_cost'])->toBe('60.000000');

    $close = closeMonth('2026-08', null, 'k-fixed');
    expect($close->revision)->toBe(1)->and((string) $close->reconciled_cash_contribution)->toBe('126.000000')
        ->and(collect($close->inputs_snapshot['reconciliations'])->firstWhere('component', 'provider')['id'])->toBe($fresh->id)
        ->and(FinancePeriodCloseInput::query()->where('close_id', $close->id)->where('input_type', 'reconciliation')->where('input_id', $fresh->id)->exists())->toBeTrue();
});

it('ledger moved AFTER a successful close is DRIFT SINCE CLOSE only: informational on the old close, no recompute, no mutation, pointer unchanged; a new figure needs reopen → fresh reconciliation → revision 2', function () {
    $fx = closableMonth();
    $service = app(PeriodCloseService::class);
    $close = closeMonth('2026-08', null, 'k1');
    expect($service->drift($close))->toBeFalse();

    // B. usage lands in the closed month after the close
    financeRow(['provider' => 'groq', 'provider_cost' => '2.000000', 'total_cost' => '2.000000', 'occurred_at' => CarbonImmutable::parse('2026-08-21 10:00:00', 'UTC')]);

    expect($service->drift($close->fresh()))->toBeTrue()
        ->and(app(ClosePreflight::class)->evaluate('2026-08')->blocking())->toBe(['LEDGER_MOVED (reconciliation:'.$fx['reconciliation']->id.')']) // live view; the close itself is not re-evaluated
        ->and(closeRule(fn () => closeMonth('2026-08', $close->id, 'k-again')))->toBe('ALREADY_CLOSED');
    expectCloseUntouched($close, '131.000000');
    expect(FinancePeriodClose::count())->toBe(1)->and(FinancePeriodCloseInput::count())->toBe(9);

    // Correction path: reopen (append-only) → still blocked until a fresh reconciliation → revision 2 chained to revision 1; revision 1 stays as it was.
    $reopen = reopen($close);
    expect(closeRule(fn () => closeMonth('2026-08', $reopen->id, 'k2')))->toBe('blocked:LEDGER_MOVED')->and(FinancePeriodClose::query()->where('status', 'closed')->count())->toBe(1);
    e2Reconcile([], ['expectedCurrentReconciliationId' => $fx['reconciliation']->id, 'source' => 'manual_evidenced', 'reconciledAmount' => '62.000000', 'reasonCode' => 'restated', 'evidenceRef' => 'stmt:aug-2']);
    $v2 = closeMonth('2026-08', $reopen->id, 'k2');
    expect($v2->revision)->toBe(2)->and($v2->previous_close_id)->toBe($close->id)->and((string) $v2->reconciled_cash_contribution)->toBe('124.000000')
        ->and((string) $close->fresh()->reconciled_cash_contribution)->toBe('131.000000')->and($close->fresh()->input_hash)->toBe($close->input_hash)
        ->and($service->drift($close->fresh()))->toBeTrue()->and($service->drift($v2))->toBeFalse();
});

it('voided or superseded evidence BEFORE close is a hard blocker (EVIDENCE_STALE, identifiers only): close refused, nothing written; a reconciliation on valid evidence makes it closable', function () {
    $fx = closableMonth();
    $service = app(CostInvoiceService::class);

    // superseded: the invoice behind the current reconciliation is replaced by a new CONFIRMED invoice
    $replacement = e2ConfirmedInvoice(['service' => '60.000000']);
    $service->supersede($fx['invoice']->id, $fx['invoice']->fresh()->stateToken(), $replacement->id, 'corrected');
    $e = app(ClosePreflight::class)->evaluate('2026-08');
    $detail = collect($e->conditions)->firstWhere('code', 'EVIDENCE_STALE')['detail'];

    expect($e->canClose())->toBeFalse()->and($e->blocking())->toBe(['EVIDENCE_STALE (reconciliation:'.$fx['reconciliation']->id.' EVIDENCE SUPERSEDED (#'.$fx['invoice']->id.' → #'.$replacement->id.'))'])
        ->and(preg_match('/^reconciliation:\d+ EVIDENCE SUPERSEDED \(#\d+ → #\d+\)$/u', $detail))->toBe(1) // ids only, no counterparty names, amounts or references
        ->and($e->metrics['reconciled_service_cost'])->toBeNull()
        ->and(fn () => closeMonth('2026-08', null, 'k-stale'))->toThrow(CloseBlockedException::class, 'EVIDENCE_STALE');
    expectNothingClosed();

    // voided: same blocker, ids only
    $second = e2ConfirmedInvoice(['service' => '60.000000']);
    $rec2 = e2Reconcile([[$second->lines()->first()->id, '60.000000']], ['expectedCurrentReconciliationId' => $fx['reconciliation']->id]);
    expect(app(ClosePreflight::class)->evaluate('2026-08')->canClose())->toBeTrue();
    $service->void($second->id, $second->fresh()->stateToken(), 'duplicate');
    expect(app(ClosePreflight::class)->evaluate('2026-08')->blocking())->toBe(['EVIDENCE_STALE (reconciliation:'.$rec2->id.' EVIDENCE VOIDED (#'.$second->id.'))'])
        ->and(fn () => closeMonth('2026-08', null, 'k-void'))->toThrow(CloseBlockedException::class, 'EVIDENCE_STALE');
    expectNothingClosed();

    // reconciled again on the valid replacement ⇒ closable
    $rec3 = e2Reconcile([[$replacement->lines()->first()->id, '60.000000']], ['expectedCurrentReconciliationId' => $rec2->id]);
    $close = closeMonth('2026-08', null, 'k-ok');
    expect((string) $close->reconciled_cash_contribution)->toBe('126.000000')
        ->and(collect($close->inputs_snapshot['reconciliations'])->firstWhere('component', 'provider')['id'])->toBe($rec3->id);
});

it('evidence voided or superseded AFTER a successful close leaves the close immutable and shows drift only; reopen → new reconciliation → new close is the correction path', function () {
    $fx = closableMonth();
    $closes = app(PeriodCloseService::class);
    $invoices = app(CostInvoiceService::class);
    $close = closeMonth('2026-08', null, 'k1');
    expect($closes->drift($close))->toBeFalse();

    $invoices->void($fx['invoice']->id, $fx['invoice']->fresh()->stateToken(), 'duplicate');

    expect($closes->drift($close->fresh()))->toBeTrue()
        ->and(app(ClosePreflight::class)->evaluate('2026-08')->blocking())->toBe(['EVIDENCE_STALE (reconciliation:'.$fx['reconciliation']->id.' EVIDENCE VOIDED (#'.$fx['invoice']->id.'))'])
        ->and(closeRule(fn () => closeMonth('2026-08', $close->id, 'k-again')))->toBe('ALREADY_CLOSED');
    expectCloseUntouched($close, '131.000000');
    expect(collect($close->fresh()->inputs_snapshot['reconciliations'])->firstWhere('component', 'provider')['flags'])->toBe([]); // the frozen snapshot never learns about the void

    $reopen = reopen($close);
    expect(closeRule(fn () => closeMonth('2026-08', $reopen->id, 'k2')))->toBe('blocked:EVIDENCE_STALE')->and(FinancePeriodClose::query()->where('status', 'closed')->count())->toBe(1);

    $replacement = e2ConfirmedInvoice(['service' => '58.000000']);
    $rec = e2Reconcile([[$replacement->lines()->first()->id, '58.000000']], ['expectedCurrentReconciliationId' => $fx['reconciliation']->id]);
    $v2 = closeMonth('2026-08', $reopen->id, 'k2');
    expect($v2->revision)->toBe(2)->and($v2->previous_close_id)->toBe($close->id)->and((string) $v2->reconciled_cash_contribution)->toBe('128.000000')
        ->and(collect($v2->inputs_snapshot['reconciliations'])->firstWhere('component', 'provider')['id'])->toBe($rec->id)
        ->and((string) $close->fresh()->reconciled_cash_contribution)->toBe('131.000000')->and($close->fresh()->input_hash)->toBe($close->input_hash)
        ->and(FinancePeriodClose::count())->toBe(3);
});

it('an unresolved payment dispute is a hard blocker for close (UNRESOLVED_DISPUTES): refused, nothing written, cash history never rewritten; resolved through the lifecycle ⇒ closable', function () {
    $fx = closableMonth();
    $payments = app(CustomerPaymentService::class);
    $payments->transition($fx['usd'], CustomerPaymentEventType::Disputed, $fx['usd']->stateToken(), PaymentSource::Gateway, 'chargeback');
    $e = app(ClosePreflight::class)->evaluate('2026-08');

    expect($e->canClose())->toBeFalse()->and($e->blocking())->toBe(['UNRESOLVED_DISPUTES (payments:'.$fx['usd']->id.')'])
        ->and($e->metrics['gross_cash_collected'])->toBe('200.00')->and($e->metrics['net_cash_after_gateway_fees'])->toBe('186.00') // historical cash stays visible
        ->and($e->metrics['reconciled_cash_contribution'])->toBeNull() // but no contribution while blocked
        ->and(collect($e->snapshot['payments'])->firstWhere('id', $fx['usd']->id)['amount'])->toBe('100.00')
        ->and(fn () => closeMonth('2026-08', null, 'k-disputed'))->toThrow(CloseBlockedException::class, 'UNRESOLVED_DISPUTES');
    expectNothingClosed();
    expect(DB::table('customer_payment_events')->where('customer_payment_id', $fx['usd']->id)->where('event_type', 'succeeded')->count())->toBe(1)->and((string) $fx['usd']->fresh()->amount)->toBe('100.00');

    $payments->transition($fx['usd']->fresh(), CustomerPaymentEventType::DisputeResolved, $fx['usd']->fresh()->stateToken(), PaymentSource::Gateway);
    $close = closeMonth('2026-08', null, 'k-resolved');
    expect((string) $close->gross_cash_collected)->toBe('200.000000')->and((string) $close->reconciled_cash_contribution)->toBe('131.000000')
        ->and(DB::table('customer_payment_events')->where('customer_payment_id', $fx['usd']->id)->orderBy('id')->pluck('event_type')->all())->toBe(['created', 'succeeded', 'disputed', 'dispute_resolved']);
});
