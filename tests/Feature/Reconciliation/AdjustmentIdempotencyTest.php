<?php

declare(strict_types=1);

use App\Exceptions\Reconciliation\ReconciliationConflictException;
use App\Exceptions\Reconciliation\StaleReconciliationException;
use App\Models\AuditLog;
use App\Models\CostAdjustment;
use App\Services\Audit\AuditLogger;
use App\Services\Reconciliation\CostReconciliationService;
use App\Services\Reconciliation\ReconciledCostQuery;
use App\Support\Audit\AuditActions;
use App\Support\Security\SecretRedactor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * E5.2b — durable, service-level idempotency for cost adjustments (no cache
 * involved anywhere): a required opaque key, replay on the same canonical
 * facts, conflict on any different fact, the DB unique index as authority,
 * audit exactly once, the base reconciled amount never touched.
 */
function adjustService(): CostReconciliationService
{
    return app(CostReconciliationService::class);
}

it('requires an opaque bounded idempotency key for EVERY new adjustment: the parameter is non-nullable and non-optional; empty / oversized / multi-line keys are refused before anything is written', function () {
    $invoice = e2ConfirmedInvoice(['service' => '100.000000']);
    $rec = e2Reconcile([[$invoice->lines()->firstOrFail()->id, '100.000000']]);

    $param = (new ReflectionMethod(CostReconciliationService::class, 'adjust'))->getParameters()[4];
    expect($param->getName())->toBe('idempotencyKey')->and($param->isOptional())->toBeFalse()->and($param->allowsNull())->toBeFalse()->and((string) $param->getType())->toBe('string');

    foreach (['', '   ', str_repeat('k', 192), "a\nb", "tab\tkey"] as $bad) {
        expect(e2Rule(fn () => adjustService()->adjust($rec->id, '-5.000000', 'credit_note', 'cn:1', $bad)))->toBe('idempotency_key', json_encode($bad));
    }
    expect(CostAdjustment::count())->toBe(0)->and(AuditLog::where('action', AuditActions::CostAdjusted)->count())->toBe(0);

    $max = str_repeat('m', 191);
    expect(adjustService()->adjust($rec->id, '-5.000000', 'credit_note', 'cn:1', ' '.$max.' ')->idempotency_key)->toBe($max);
});

it('same key + same canonical facts ⇒ the SAME adjustment (no row, no audit, base untouched, Adjusted unchanged); same key + any different fact ⇒ conflict with nothing written', function () {
    $invoice = e2ConfirmedInvoice(['service' => '100.000000']);
    $rec = e2Reconcile([[$invoice->lines()->firstOrFail()->id, '100.000000']]);
    $other = e2Reconcile([[$invoice->lines()->firstOrFail()->id, '0.000000']], ['month' => '2026-07', 'allocations' => [], 'source' => 'manual_evidenced', 'reconciledAmount' => '1.000000', 'reasonCode' => 'x', 'evidenceRef' => 'y']);
    $key = 'ui:'.str()->uuid();
    $audits = fn () => AuditLog::where('action', AuditActions::CostAdjusted)->count();

    $first = adjustService()->adjust($rec->id, '-5.000000', 'credit_note', 'cn:1', $key);
    $replay = adjustService()->adjust($rec->id, '-5', 'credit_note', 'cn:1', $key); // "-5" normalises to the same canonical amount
    expect($first->wasRecentlyCreated)->toBeTrue()->and($replay->wasRecentlyCreated)->toBeFalse()->and($replay->id)->toBe($first->id)
        ->and(CostAdjustment::count())->toBe(1)->and($audits())->toBe(1)
        ->and((string) $rec->fresh()->reconciled_amount)->toBe('100.000000')
        ->and(app(ReconciledCostQuery::class)->describe($rec->scope()->firstOrFail())->adjustedReconciledCost)->toBe('95.000000');

    foreach ([
        'amount' => [$rec->id, '-6.000000', 'credit_note', 'cn:1'],
        'reconciliation' => [$other->id, '-5.000000', 'credit_note', 'cn:1'],
        'reason_code' => [$rec->id, '-5.000000', 'late_fee', 'cn:1'],
        'evidence_ref' => [$rec->id, '-5.000000', 'credit_note', 'cn:2'],
    ] as $changed => [$r, $amount, $reason, $evidence]) {
        expect(fn () => adjustService()->adjust($r, $amount, $reason, $evidence, $key))->toThrow(ReconciliationConflictException::class, 'بحقائق مختلفة', $changed);
    }
    expect(CostAdjustment::count())->toBe(1)->and($audits())->toBe(1)->and((string) $first->fresh()->amount)->toBe('-5.000000');
});

it('a replay is answered before the pointer check, a NEW key on a superseded reconciliation is still stale, and the unique index refuses a second row while historical NULL keys coexist', function () {
    $invoice = e2ConfirmedInvoice(['service' => '100.000000']);
    $line = $invoice->lines()->firstOrFail();
    $rec = e2Reconcile([[$line->id, '60.000000']]);
    $key = 'adj-'.str()->random(6);
    $first = adjustService()->adjust($rec->id, '-5.000000', 'credit_note', 'cn:1', $key);

    $rec2 = e2Reconcile([[$line->id, '40.000000']], ['expectedCurrentReconciliationId' => $rec->id]); // supersedes rec
    expect(adjustService()->adjust($rec->id, '-5.000000', 'credit_note', 'cn:1', $key)->id)->toBe($first->id) // replay of a done operation
        ->and(fn () => adjustService()->adjust($rec->id, '-1.000000', 'x', 'y', e2Key()))->toThrow(StaleReconciliationException::class)
        ->and(CostAdjustment::count())->toBe(1);

    $row = fn (?string $k) => ['cost_reconciliation_id' => $rec2->id, 'amount' => '-1.000000', 'currency' => 'USD', 'reason_code' => 'x', 'evidence_ref' => 'y', 'actor_ref' => 'test', 'idempotency_key' => $k, 'created_at' => now()];
    expect(fn () => DB::transaction(fn () => DB::table('cost_adjustments')->insert($row($key))))->toThrow(UniqueConstraintViolationException::class);
    DB::table('cost_adjustments')->insert($row(null));
    DB::table('cost_adjustments')->insert($row(null));
    expect(CostAdjustment::count())->toBe(3)->and(CostAdjustment::query()->whereNull('idempotency_key')->count())->toBe(2)
        ->and(Schema::hasIndex('cost_adjustments', 'cost_adjustments_idempotency_key_unique'))->toBeTrue();
});

it('survives the unique race path: a same-key row appearing between the pre-check and the insert is caught in the savepoint and returned (same facts) or refused (different facts); the connection stays usable; the loser writes no audit', function () {
    $invoice = e2ConfirmedInvoice(['service' => '100.000000']);
    $rec = e2Reconcile([[$invoice->lines()->firstOrFail()->id, '100.000000']]);
    $audits = fn () => AuditLog::where('action', AuditActions::CostAdjusted)->count();
    $key = 'race-'.str()->random(6);

    // "another process" commits a same-key row right after this process's pre-check (the SELECT by idempotency_key) and before its INSERT
    $inject = function (string $amount) use ($rec, &$key): void {
        $done = false;
        DB::listen(function (QueryExecuted $q) use (&$done, $rec, &$key, $amount): void {
            if ($done || ! str_contains($q->sql, 'cost_adjustments') || ! str_contains($q->sql, 'idempotency_key') || ! str_starts_with(ltrim($q->sql), 'select')) {
                return;
            }
            $done = true;
            DB::table('cost_adjustments')->insert(['cost_reconciliation_id' => $rec->id, 'amount' => $amount, 'currency' => 'USD', 'reason_code' => 'credit_note', 'evidence_ref' => 'cn:1', 'actor_ref' => 'other-process', 'idempotency_key' => $key, 'created_at' => now()]);
        });
    };

    $inject('-5.000000');
    $won = adjustService()->adjust($rec->id, '-5.000000', 'credit_note', 'cn:1', $key);
    expect($won->wasRecentlyCreated)->toBeFalse()->and($won->actor_ref)->toBe('other-process')->and(CostAdjustment::count())->toBe(1)->and($audits())->toBe(0);

    $key = 'race-'.str()->random(6);
    $inject('-6.000000');
    expect(fn () => adjustService()->adjust($rec->id, '-5.000000', 'credit_note', 'cn:1', $key))->toThrow(ReconciliationConflictException::class, 'بحقائق مختلفة (#')
        ->and($audits())->toBe(0);

    expect(adjustService()->adjust($rec->id, '-1.000000', 'credit_note', 'cn:3', e2Key())->wasRecentlyCreated)->toBeTrue()->and($audits())->toBe(1);
});

it('audit exactly once: a real creation writes one audit, a replay none, a conflict none; an audit failure rolls the adjustment back', function () {
    $invoice = e2ConfirmedInvoice(['service' => '100.000000']);
    $rec = e2Reconcile([[$invoice->lines()->firstOrFail()->id, '100.000000']]);
    $key = e2Key();
    $audits = fn () => AuditLog::where('action', AuditActions::CostAdjusted)->count();

    adjustService()->adjust($rec->id, '-5.000000', 'credit_note', 'cn:1', $key);
    adjustService()->adjust($rec->id, '-5.000000', 'credit_note', 'cn:1', $key);
    expect(fn () => adjustService()->adjust($rec->id, '-6.000000', 'credit_note', 'cn:1', $key))->toThrow(ReconciliationConflictException::class);
    expect($audits())->toBe(1)->and(CostAdjustment::count())->toBe(1);

    app()->instance(AuditLogger::class, new class(app(SecretRedactor::class)) extends AuditLogger
    {
        public function record(string $action, ?Model $subject = null, array $changes = [], array $context = []): AuditLog
        {
            throw new RuntimeException('audit store unavailable');
        }
    });
    expect(fn () => adjustService()->adjust($rec->id, '-1.000000', 'credit_note', 'cn:9', e2Key()))->toThrow(RuntimeException::class)
        ->and(CostAdjustment::count())->toBe(1)->and($audits())->toBe(1);
});
