<?php

declare(strict_types=1);

use App\Enums\CostCoverageStatus;
use App\Enums\CostSource;
use App\Exceptions\Payments\ImmutableFinancialRecordException;
use App\Exceptions\Reconciliation\StaleReconciliationException;
use App\Models\AuditLog;
use App\Models\CostAdjustment;
use App\Models\CostInvoiceAllocation;
use App\Models\CostReconciliation;
use App\Models\CostReconciliationScope;
use App\Models\UsageEvent;
use App\Services\Audit\AuditLogger;
use App\Services\Reconciliation\CostInvoiceService;
use App\Services\Reconciliation\CostReconciliationService;
use App\Services\Reconciliation\ReconciledCostQuery;
use App\Support\Audit\AuditActions;
use App\Support\Security\SecretRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Phase E2 — reconciliation semantics: scope projection + stale protection,
 * explicit multi-invoice evidence, signed caps across ALL reconciliations,
 * tax / other never allocatable, credits as negative evidence, FX_REQUIRED,
 * known-vs-unknown calculated cost, confirmed zero as an attestation, ledger
 * snapshot + moved detection, append-only adjustments, atomic audit.
 */
function e2Ledger(string $when, string $cost, string $provider = 'groq', array $attrs = []): void
{
    financeRow(array_merge(['provider' => $provider, 'provider_cost' => $cost, 'total_cost' => $cost, 'occurred_at' => CarbonImmutable::parse($when, 'UTC')], $attrs));
}

function e2Summary(string $month = '2026-08'): array
{
    return app(ReconciledCostQuery::class)->summarise($month, $month);
}

it('reconciles one month from EXPLICIT evidence spread over several invoices: the reconciled amount is Σ allocations, never an invoice total', function () {
    e2Ledger('2026-08-10 10:00:00', '40.000000');
    e2Ledger('2026-08-20 10:00:00', '55.500000');
    $a = e2ConfirmedInvoice(['service' => '120.000000', 'tax' => '19.200000'], ['invoiceRef' => 'A']); // covers August
    $b = e2ConfirmedInvoice(['service' => '200.000000'], ['invoiceRef' => 'B', 'periodStart' => CarbonImmutable::parse('2026-08-15', 'UTC'), 'periodEnd' => CarbonImmutable::parse('2026-10-15', 'UTC')]); // two months
    $aService = $a->lines()->where('kind', 'service')->firstOrFail();
    $bService = $b->lines()->firstOrFail();

    $rec = e2Reconcile([[$aService->id, '120.000000'], [$bService->id, '30.000000']]);
    $scope = CostReconciliationScope::query()->firstOrFail();

    expect((string) $rec->reconciled_amount)->toBe('150.000000') // 120 + 30 — not 139.2 (A total) nor 200 (B total)
        ->and($rec->source->value)->toBe('invoice')
        ->and($rec->period_start->utc()->format('Y-m-d H:i:s'))->toBe('2026-08-01 00:00:00')->and($rec->period_end->utc()->format('Y-m-d H:i:s'))->toBe('2026-09-01 00:00:00')
        ->and((string) $rec->calculated_known_amount)->toBe('95.500000')->and($rec->calculated_priced_rows)->toBe(2)->and($rec->unpriced_rows)->toBe(0)
        ->and($rec->cost_coverage_status)->toBe(CostCoverageStatus::Complete)
        ->and($rec->supersedes_id)->toBeNull()
        ->and($scope->current_reconciliation_id)->toBe($rec->id)->and($scope->version)->toBe(1)->and($scope->stateToken())->toBe('r:'.$rec->id)
        ->and(CostInvoiceAllocation::query()->where('cost_reconciliation_id', $rec->id)->count())->toBe(2)
        ->and(AuditLog::where('action', AuditActions::CostReconciled)->count())->toBe(1)
        ->and(AuditLog::where('action', AuditActions::CostReconciled)->first()->metadata['changes']['current_reconciliation_id'])->toBe(['from' => null, 'to' => $rec->id]);

    // September takes the remaining 170 of invoice B; a third month then finds nothing left (cap across reconciliations).
    $sep = e2Reconcile([[$bService->id, '170.000000']], ['month' => '2026-09']);
    expect((string) $sep->reconciled_amount)->toBe('170.000000')
        ->and(e2Rule(fn () => e2Reconcile([[$bService->id, '0.000001']], ['month' => '2026-10'])))->toBe('allocation_limit')
        ->and(CostReconciliationScope::count())->toBe(2)
        ->and(CostReconciliation::count())->toBe(2);

    $summary = e2Summary();
    expect($summary)->toHaveCount(1)->and($summary[0]->status)->toBe('RECONCILED')->and($summary[0]->baseReconciledAmount)->toBe('150.000000')
        ->and($summary[0]->varianceVsKnownCalculated)->toBe('54.500000')->and($summary[0]->varianceStatus)->toBe('KNOWN')->and($summary[0]->ledgerMoved)->toBeFalse();
});

it('never allocates tax or other lines, treats credits as negative evidence with the line sign, and caps by absolute value without clipping', function () {
    $inv = e2ConfirmedInvoice(['service' => '100.000000', 'tax' => '16.000000', 'other' => '5.000000', 'credit' => '-30.000000']);
    [$service, $tax, $other, $credit] = $inv->lines()->orderBy('line_no')->get()->all();

    expect(e2Rule(fn () => e2Reconcile([[$tax->id, '16.000000']])))->toBe('line_kind')
        ->and(e2Rule(fn () => e2Reconcile([[$other->id, '5.000000']])))->toBe('line_kind')
        ->and(e2Rule(fn () => e2Reconcile([[$credit->id, '30.000000']])))->toBe('sign') // a credit must be allocated negatively
        ->and(e2Rule(fn () => e2Reconcile([[$service->id, '-1.000000']])))->toBe('sign')
        ->and(e2Rule(fn () => e2Reconcile([[$service->id, '100.000001']])))->toBe('allocation_limit')
        ->and(e2Rule(fn () => e2Reconcile([[$credit->id, '-30.000001']])))->toBe('allocation_limit')
        ->and(e2Rule(fn () => e2Reconcile([[$service->id, '60.000000'], [$service->id, '50.000000']])))->toBe('allocation_limit') // same request, twice the line
        ->and(e2Rule(fn () => e2Reconcile([[$service->id, '0']])))->toBe('allocation_amount')
        ->and(CostReconciliation::count())->toBe(0)->and(CostInvoiceAllocation::count())->toBe(0);

    $rec = e2Reconcile([[$service->id, '100.000000'], [$credit->id, '-30.000000']]);
    expect((string) $rec->reconciled_amount)->toBe('70.000000') // 100 − 30; tax/other untouched
        ->and(CostInvoiceAllocation::query()->where('cost_invoice_line_id', $credit->id)->sum('amount'))->toEqual(-30);

    // The credit is fully used: another month cannot use it again; the service line is exhausted too.
    expect(e2Rule(fn () => e2Reconcile([[$credit->id, '-0.000001']], ['month' => '2026-09'])))->toBe('allocation_limit')
        ->and(e2Rule(fn () => e2Reconcile([[$service->id, '0.000001']], ['month' => '2026-09'])))->toBe('allocation_limit');
});

it('refuses evidence that is not a confirmed invoice of the same component / counterparty, and a different currency is FX_REQUIRED (no implicit conversion)', function () {
    $draft = e2Invoice();
    $draftLine = e2Line($draft);
    $eur = e2ConfirmedInvoice(['service' => '80.000000'], ['currency' => 'EUR']);
    $openai = e2ConfirmedInvoice(['service' => '80.000000'], ['counterpartyKey' => 'openai']);
    $comm = e2ConfirmedInvoice(['service' => '80.000000'], ['component' => 'communication', 'counterpartyKey' => 'meta-whatsapp']);

    expect(e2Rule(fn () => e2Reconcile([[$draftLine->id, '1']])))->toBe('invoice_status')
        ->and(e2Rule(fn () => e2Reconcile([[$eur->lines()->first()->id, '1']])))->toBe('FX_REQUIRED')
        ->and(e2Rule(fn () => e2Reconcile([[$openai->lines()->first()->id, '1']])))->toBe('scope_mismatch')
        ->and(e2Rule(fn () => e2Reconcile([[$comm->lines()->first()->id, '1']])))->toBe('scope_mismatch')
        ->and(e2Rule(fn () => e2Reconcile([[999999, '1']])))->toBe('line')
        ->and(e2Rule(fn () => e2Reconcile([])))->toBe('allocations')
        ->and(e2Rule(fn () => e2Reconcile([[$openai->lines()->first()->id, '1']], ['month' => '2026-8'])))->toBe('period')
        ->and(e2Rule(fn () => e2Reconcile([[$openai->lines()->first()->id, '1']], ['month' => '2026-08-01'])))->toBe('period')
        ->and(CostReconciliation::count())->toBe(0)->and(CostReconciliationScope::count())->toBe(0); // no scope row is left behind by a refused request

    // Same currency on both sides works, per counterparty.
    $rec = e2Reconcile([[$openai->lines()->first()->id, '80.000000']], ['counterpartyKey' => 'openai']);
    expect($rec->counterparty_key)->toBe('openai');
});

it('refuses a stale expected pointer (never last-writer-wins) and lets the refreshed caller supersede: pointer moves, history stays', function () {
    $inv = e2ConfirmedInvoice(['service' => '100.000000']);
    $line = $inv->lines()->firstOrFail();
    $first = e2Reconcile([[$line->id, '60.000000']]);

    // Someone acting on the "no reconciliation yet" view is refused; the pointer is untouched.
    expect(fn () => e2Reconcile([[$line->id, '10.000000']], ['expectedCurrentReconciliationId' => null]))->toThrow(StaleReconciliationException::class)
        ->and(fn () => e2Reconcile([[$line->id, '10.000000']], ['expectedCurrentReconciliationId' => 999]))->toThrow(StaleReconciliationException::class)
        ->and(CostReconciliationScope::query()->firstOrFail()->current_reconciliation_id)->toBe($first->id)
        ->and(CostReconciliation::count())->toBe(1)->and(CostInvoiceAllocation::count())->toBe(1);

    $second = e2Reconcile([[$line->id, '40.000000']], ['expectedCurrentReconciliationId' => $first->id]);
    $scope = CostReconciliationScope::query()->firstOrFail();

    expect($second->supersedes_id)->toBe($first->id)
        ->and($scope->current_reconciliation_id)->toBe($second->id)->and($scope->version)->toBe(2)
        ->and(CostReconciliation::count())->toBe(2) // append-only: the first row is still there
        ->and((string) $first->fresh()->reconciled_amount)->toBe('60.000000')
        ->and(fn () => $first->fresh()->forceFill(['reconciled_amount' => '1'])->save())->toThrow(ImmutableFinancialRecordException::class)
        ->and(fn () => $first->delete())->toThrow(ImmutableFinancialRecordException::class)
        ->and(fn () => $scope->forceFill(['currency' => 'EUR'])->save())->toThrow(ImmutableFinancialRecordException::class)
        ->and(fn () => $scope->delete())->toThrow(ImmutableFinancialRecordException::class)
        ->and(e2Summary()[0]->reconciliationId)->toBe($second->id)->and(e2Summary()[0]->baseReconciledAmount)->toBe('40.000000');
});

it('separates KNOWN calculated cost from unknown: unpriced or mismatched rows make the variance UNKNOWN, and a component without a producer never yields a numeric variance', function () {
    e2Ledger('2026-08-05 10:00:00', '10.000000');
    e2Ledger('2026-08-06 10:00:00', '0.000000', 'groq', ['cost_source' => CostSource::None]); // unpriced
    e2Ledger('2026-08-07 10:00:00', '3.000000', 'groq', ['currency' => 'EUR']); // priced in another currency
    e2Ledger('2026-08-08 10:00:00', '99.000000', 'openai'); // another counterparty — out of scope
    e2Ledger('2026-07-31 23:59:59', '77.000000'); // outside the month
    $inv = e2ConfirmedInvoice(['service' => '25.000000']);

    $rec = e2Reconcile([[$inv->lines()->first()->id, '25.000000']]);
    expect((string) $rec->calculated_known_amount)->toBe('10.000000') // only the priced USD groq row in August
        ->and($rec->calculated_priced_rows)->toBe(1)->and($rec->unpriced_rows)->toBe(1)->and($rec->currency_mismatch_rows)->toBe(1)
        ->and($rec->cost_coverage_status)->toBe(CostCoverageStatus::Partial);

    $row = e2Summary()[0];
    expect($row->calculatedKnownAmount)->toBe('10.000000')->and($row->varianceVsKnownCalculated)->toBeNull()
        ->and($row->varianceStatus)->toBe('UNKNOWN (PARTIAL CALCULATED COVERAGE)')->and($row->coverage)->toContain('PARTIAL');

    // communication: NO PRODUCER — an invoice of 100 against a calculated 0 is not a +100 variance.
    $meta = e2ConfirmedInvoice(['service' => '100.000000'], ['component' => 'communication', 'counterpartyKey' => 'meta-whatsapp']);
    $commRec = e2Reconcile([[$meta->lines()->first()->id, '100.000000']], ['component' => 'communication', 'counterpartyKey' => 'meta-whatsapp']);
    $comm = collect(e2Summary())->firstWhere('component', 'communication');
    expect($commRec->cost_coverage_status)->toBe(CostCoverageStatus::NoProducer)->and((string) $commRec->calculated_known_amount)->toBe('0.000000')
        ->and($comm->varianceVsKnownCalculated)->toBeNull()->and($comm->adjustedVarianceVsKnownCalculated)->toBeNull()
        ->and($comm->varianceStatus)->toBe('UNKNOWN (NO PRODUCER)')->and($comm->baseReconciledAmount)->toBe('100.000000');
});

it('records CONFIRMED ZERO only as a typed, reasoned, evidenced attestation, reports it as CONFIRMED ZERO, and never as a way to hide UNKNOWN', function () {
    $zero = fn (array $o = []) => e2Reconcile([], array_merge(['component' => 'external', 'counterpartyKey' => 'none-declared', 'source' => 'confirmed_zero', 'reasonCode' => 'no_external_services', 'evidenceRef' => 'attestation:2026-08', 'typedConfirmation' => 'ZERO'], $o));

    expect(e2Rule(fn () => $zero(['typedConfirmation' => null])))->toBe('typed_confirmation')
        ->and(e2Rule(fn () => $zero(['typedConfirmation' => 'zero'])))->toBe('typed_confirmation')
        ->and(e2Rule(fn () => $zero(['reasonCode' => null])))->toBe('evidence')
        ->and(e2Rule(fn () => $zero(['evidenceRef' => null])))->toBe('evidence')
        ->and(e2Rule(fn () => $zero(['reconciledAmount' => '5'])))->toBe('confirmed_zero')
        ->and(CostReconciliation::count())->toBe(0);

    $rec = $zero();
    expect((string) $rec->reconciled_amount)->toBe('0.000000')->and($rec->source->value)->toBe('confirmed_zero')
        ->and($rec->cost_coverage_status)->toBe(CostCoverageStatus::NoProducer)->and($rec->actor_ref)->toBe('console')->and($rec->reason_code)->toBe('no_external_services')
        ->and(AuditLog::where('action', AuditActions::CostReconciled)->first()->metadata['context']['typed_confirmation'])->toBe('ZERO')
        ->and(AuditLog::where('action', AuditActions::CostReconciled)->first()->metadata['context']['source'])->toBe('confirmed_zero');

    $row = e2Summary()[0];
    expect($row->status)->toBe('CONFIRMED ZERO')->and($row->baseReconciledAmount)->toBe('0.000000')
        ->and($row->varianceStatus)->toBe('UNKNOWN (NO PRODUCER)')->and($row->varianceVsKnownCalculated)->toBeNull(); // zero attested ≠ known variance 0

    // A manual evidenced amount of zero is refused: zero goes through the attestation path only.
    expect(e2Rule(fn () => e2Reconcile([], ['source' => 'manual_evidenced', 'reconciledAmount' => '0', 'reasonCode' => 'x', 'evidenceRef' => 'y'])))->toBe('reconciled_amount')
        ->and(e2Rule(fn () => e2Reconcile([], ['source' => 'manual_evidenced', 'reconciledAmount' => '12.5', 'reasonCode' => null, 'evidenceRef' => 'y'])))->toBe('evidence');
    $manual = e2Reconcile([], ['source' => 'manual_evidenced', 'reconciledAmount' => '12.500000', 'reasonCode' => 'statement', 'evidenceRef' => 'stmt:2026-08']);
    expect((string) $manual->reconciled_amount)->toBe('12.500000')->and($manual->source->value)->toBe('manual_evidenced');
});

it('freezes a canonical ledger snapshot and flags LEDGER MOVED SINCE RECONCILIATION when later rows land in the same month, without touching the old reconciliation', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));
    e2Ledger('2026-08-10 10:00:00', '10.000000');
    $inv = e2ConfirmedInvoice(['service' => '10.000000']);
    $rec = e2Reconcile([[$inv->lines()->first()->id, '10.000000']]);
    $maxId = UsageEvent::query()->max('id');

    expect($rec->ledger_max_event_id)->toBe($maxId)->and($rec->captured_at->equalTo(CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC')))->toBeTrue()
        ->and(strlen($rec->snapshot_hash))->toBe(64)
        ->and(e2Summary()[0]->ledgerMoved)->toBeFalse()->and(e2Summary()[0]->flags)->toBe([]);

    // A late row for August arrives (same amount as an existing one — count and max id still change).
    e2Ledger('2026-08-11 10:00:00', '10.000000');

    $row = e2Summary()[0];
    expect($row->ledgerMoved)->toBeTrue()->and($row->flags)->toContain('LEDGER MOVED SINCE RECONCILIATION')
        ->and($row->calculatedKnownAmount)->toBe('10.000000') // the frozen snapshot, not the live 20
        ->and((string) $rec->fresh()->calculated_known_amount)->toBe('10.000000')
        ->and($rec->fresh()->snapshot_hash)->toBe($rec->snapshot_hash)
        ->and($row->varianceVsKnownCalculated)->toBe('0.000000');

    // A row for another month or another counterparty does not move this scope.
    $again = e2Reconcile([], ['expectedCurrentReconciliationId' => $rec->id, 'source' => 'manual_evidenced', 'reconciledAmount' => '20.000000', 'reasonCode' => 'restated', 'evidenceRef' => 'stmt']);
    e2Ledger('2026-09-11 10:00:00', '10.000000');
    e2Ledger('2026-08-12 10:00:00', '10.000000', 'openai');
    expect(e2Summary()[0]->ledgerMoved)->toBeFalse()->and(e2Summary()[0]->reconciliationId)->toBe($again->id);
});

it('appends adjustments to the CURRENT reconciliation only, keeps the base amount and the original variance, and reports the adjusted figures separately', function () {
    e2Ledger('2026-08-10 10:00:00', '90.000000');
    $inv = e2ConfirmedInvoice(['service' => '100.000000']);
    $rec = e2Reconcile([[$inv->lines()->first()->id, '100.000000']]);
    $service = app(CostReconciliationService::class);

    expect(e2Rule(fn () => $service->adjust($rec->id, '0', 'x', 'y')))->toBe('amount')
        ->and(e2Rule(fn () => $service->adjust($rec->id, '-5', '', 'y')))->toBe('reason_code')
        ->and(e2Rule(fn () => $service->adjust($rec->id, '-5', 'x', '')))->toBe('evidence_ref')
        ->and(e2Rule(fn () => $service->adjust(999, '-5', 'x', 'y')))->toBe('reconciliation');

    $a1 = $service->adjust($rec->id, '-7.500000', 'credit_note', 'cn:1');
    $a2 = $service->adjust($rec->id, '2.000000', 'late_fee', 'stmt:2');
    $row = e2Summary()[0];

    expect((string) $a1->amount)->toBe('-7.500000')->and($a1->currency)->toBe('USD')
        ->and($row->baseReconciledAmount)->toBe('100.000000')->and($row->adjustments)->toBe('-5.500000')->and($row->adjustedReconciledCost)->toBe('94.500000')
        ->and($row->varianceVsKnownCalculated)->toBe('10.000000') // original, never rewritten
        ->and($row->adjustedVarianceVsKnownCalculated)->toBe('4.500000')
        ->and((string) $rec->fresh()->reconciled_amount)->toBe('100.000000')
        ->and(fn () => $a1->forceFill(['amount' => '1'])->save())->toThrow(ImmutableFinancialRecordException::class)
        ->and(fn () => $a1->delete())->toThrow(ImmutableFinancialRecordException::class)
        ->and(AuditLog::where('action', AuditActions::CostAdjusted)->count())->toBe(2);

    // After a superseding reconciliation the old one accepts no adjustments.
    $newer = e2Reconcile([], ['expectedCurrentReconciliationId' => $rec->id, 'source' => 'manual_evidenced', 'reconciledAmount' => '95.000000', 'reasonCode' => 'restated', 'evidenceRef' => 'stmt']);
    expect(fn () => $service->adjust($rec->id, '1', 'x', 'y'))->toThrow(StaleReconciliationException::class)
        ->and(CostAdjustment::count())->toBe(2)
        ->and(e2Summary()[0]->adjustments)->toBe('0.000000') // adjustments belong to the superseded row, not carried over
        ->and(e2Summary()[0]->baseReconciledAmount)->toBe('95.000000');
    $a2->refresh();
});

it('flags evidence that was voided or superseded after the reconciliation, keeping the reconciliation itself untouched', function () {
    $inv = e2ConfirmedInvoice(['service' => '100.000000']);
    $rec = e2Reconcile([[$inv->lines()->first()->id, '100.000000']]);
    $replacement = e2ConfirmedInvoice(['service' => '98.000000']);
    app(CostInvoiceService::class)->supersede($inv->id, $inv->stateToken(), $replacement->id, 'reissued');

    $row = e2Summary()[0];
    expect($row->flags)->toContain('EVIDENCE SUPERSEDED (#'.$inv->id.' → #'.$replacement->id.')')
        ->and($row->baseReconciledAmount)->toBe('100.000000')->and((string) $rec->fresh()->reconciled_amount)->toBe('100.000000');
});

it('is atomic: a failing audit store leaves no reconciliation, allocation or scope pointer change', function () {
    $inv = e2ConfirmedInvoice(['service' => '100.000000']);
    $line = $inv->lines()->firstOrFail();
    $first = e2Reconcile([[$line->id, '10.000000']]);

    app()->instance(AuditLogger::class, new class(app(SecretRedactor::class)) extends AuditLogger
    {
        public function record(string $action, ?Model $subject = null, array $changes = [], array $context = []): AuditLog
        {
            throw new RuntimeException('audit store unavailable');
        }
    });

    expect(fn () => e2Reconcile([[$line->id, '20.000000']], ['expectedCurrentReconciliationId' => $first->id]))->toThrow(RuntimeException::class);
    expect(fn () => app(CostReconciliationService::class)->adjust($first->id, '1', 'x', 'y'))->toThrow(RuntimeException::class);
    expect(fn () => e2Reconcile([[$line->id, '5.000000']], ['month' => '2026-09']))->toThrow(RuntimeException::class);

    expect(CostReconciliation::count())->toBe(1)->and(CostInvoiceAllocation::count())->toBe(1)->and(CostAdjustment::count())->toBe(0)
        ->and(CostReconciliationScope::count())->toBe(1) // the September scope row rolled back with its reconciliation
        ->and(CostReconciliationScope::query()->firstOrFail()->current_reconciliation_id)->toBe($first->id)
        ->and(AuditLog::where('action', AuditActions::CostReconciled)->count())->toBe(1);
});

it('refuses windows longer than 13 months or inverted, and lists only scopes in the window', function () {
    $q = app(ReconciledCostQuery::class);
    expect(fn () => $q->summarise('2026-09', '2026-08'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $q->summarise('2025-01', '2026-03'))->toThrow(InvalidArgumentException::class)
        ->and($q->summarise('2026-01', '2026-12'))->toBe([]);
});
