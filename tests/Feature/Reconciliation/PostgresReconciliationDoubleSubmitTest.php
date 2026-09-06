<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\CostAdjustment;
use App\Models\CostInvoiceEvent;
use App\Models\CostInvoiceLine;
use App\Models\CostReconciliation;
use App\Models\CostReconciliationScope;
use App\Services\Reconciliation\ReconciledCostQuery;
use Illuminate\Support\Facades\DB;

/**
 * GENUINE parallel double-submit proofs for Phase E5.2b on PostgreSQL
 * (separate PHP processes, separate connections, no cache claim anywhere):
 *  - adjustments (durable key): 6 concurrent same-key −5 on a Base 100
 *    reconciliation ⇒ ONE row, Adjustments −5, Adjusted 95, one audit, every
 *    response names the same adjustment id (never −30); same key + −6 ⇒
 *    conflict; a mixed −5 / −6 race ⇒ one canonical row;
 *  - reconcile (pointer contract): 6 from the same expected pointer ⇒ 1 ok /
 *    5 stale, one reconciliation, one pointer move, one audit;
 *  - confirm (token contract): 6 from the same token ⇒ 1 ok / 5 stale;
 *  - add line (unique (invoice, line_no)): 6 of the same line_no ⇒ one row.
 */
beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Real concurrency test requires the pgsql connection.');
    }

    try {
        DB::connection()->getPdo();
    } catch (Throwable) {
        $this->markTestSkipped('PostgreSQL is not reachable.');
    }
});

/** @return array{ok: list<string>, existing: list<string>, conflict: int, stale: int, rejected: list<string>} */
function e2Outcome(array $outcomes): array
{
    $ids = fn (string $prefix) => array_values(array_map(fn ($o) => substr($o, strlen($prefix)), array_filter($outcomes, fn ($o) => str_starts_with($o, $prefix))));

    return ['ok' => $ids('ok:'), 'existing' => $ids('existing:'), 'conflict' => count(array_filter($outcomes, fn ($o) => $o === 'conflict')), 'stale' => count(array_filter($outcomes, fn ($o) => $o === 'stale')), 'rejected' => array_values(array_filter($outcomes, fn ($o) => str_starts_with($o, 'rejected:')))];
}

it('adjustments: 6 concurrent same-key −5.000000 on Base 100 ⇒ one row, Adjustments −5, Adjusted 95, one audit, the same adjustment id everywhere; same key + −6 ⇒ conflict; mixed −5 / −6 ⇒ one canonical row', function () {
    $cp = e2Counterparty();
    $invoice = e2ConfirmedInvoice(['service' => '100.000000'], ['counterpartyKey' => $cp]);
    $rec = e2Reconcile([[$invoice->lines()->firstOrFail()->id, '100.000000']], ['counterpartyKey' => $cp]);
    $scope = CostReconciliationScope::query()->findOrFail($rec->scope_id);
    $key = 'ui:adj-'.str()->random(8);
    $audits = fn () => AuditLog::where('action', 'cost.adjusted')->where('subject_id', $scope->id)->count();
    $describe = fn () => app(ReconciledCostQuery::class)->describe($scope->fresh());

    try {
        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = e2Run(['--', 'adjust', (string) $rec->id, '-5.000000', 'credit_note', 'cn:1', $key]);
        }
        $o = e2Outcome(e2Outcomes($processes));
        $row = CostAdjustment::query()->where('cost_reconciliation_id', $rec->id)->sole();
        $summary = $describe();

        expect($o['ok'])->toHaveCount(1, json_encode($o))->and($o['existing'])->toHaveCount(5)->and($o['conflict'])->toBe(0)->and($o['stale'])->toBe(0)->and($o['rejected'])->toBe([])
            ->and(array_unique([...$o['ok'], ...$o['existing']]))->toBe([(string) $row->id])
            ->and((string) $row->amount)->toBe('-5.000000')->and($row->idempotency_key)->toBe($key)
            ->and($summary->baseReconciledAmount)->toBe('100.000000')->and($summary->adjustments)->toBe('-5.000000')->and($summary->adjustedReconciledCost)->toBe('95.000000')
            ->and($audits())->toBe(1);

        $same = e2Run(['--', 'adjust', (string) $rec->id, '-5.000000', 'credit_note', 'cn:1', $key]);
        $same->wait();
        $diff = e2Run(['--', 'adjust', (string) $rec->id, '-6.000000', 'credit_note', 'cn:1', $key]);
        $diff->wait();
        expect(trim($same->getOutput()))->toBe('existing:'.$row->id)->and(trim($diff->getOutput()))->toBe('conflict')
            ->and(CostAdjustment::query()->where('cost_reconciliation_id', $rec->id)->count())->toBe(1)->and($describe()->adjustedReconciledCost)->toBe('95.000000')->and($audits())->toBe(1);

        // mixed race under a second key: 3 × −5 and 3 × −6 ⇒ exactly one canonical row, same-amount requests get it, the others conflict
        $key2 = 'ui:adj-'.str()->random(8);
        $processes = [];
        foreach (['-5.000000', '-6.000000', '-5.000000', '-6.000000', '-5.000000', '-6.000000'] as $amount) {
            $processes[] = e2Run(['--', 'adjust', (string) $rec->id, $amount, 'credit_note', 'cn:2', $key2]);
        }
        $o = e2Outcome(e2Outcomes($processes));
        $second = CostAdjustment::query()->where('idempotency_key', $key2)->sole();
        expect($o['ok'])->toHaveCount(1, json_encode($o))->and($o['existing'])->toHaveCount(2)->and($o['conflict'])->toBe(3)
            ->and(array_unique([...$o['ok'], ...$o['existing']]))->toBe([(string) $second->id])
            ->and((string) $second->amount)->toBeIn(['-5.000000', '-6.000000'])
            ->and(CostAdjustment::query()->where('cost_reconciliation_id', $rec->id)->count())->toBe(2)->and($audits())->toBe(2)
            ->and((string) $rec->fresh()->reconciled_amount)->toBe('100.000000');
    } finally {
        e2Cleanup($cp);
    }
});

it('reconcile: 6 concurrent requests from the same expected pointer ⇒ 1 ok / 5 stale, one reconciliation, one pointer move, one audit; the loser must re-decide from the new pointer', function () {
    $cp = e2Counterparty();
    $invoice = e2ConfirmedInvoice(['service' => '600.000000'], ['counterpartyKey' => $cp]);
    $line = $invoice->lines()->firstOrFail();

    try {
        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = e2Run(['reconcile', 'provider', $cp, '2026-08', 'USD', 'none', $line->id.':10.000000']);
        }
        $o = e2Outcome(e2Outcomes($processes));
        $scope = CostReconciliationScope::query()->where('counterparty_key', $cp)->sole();

        expect($o['ok'])->toHaveCount(1, json_encode($o))->and($o['stale'])->toBe(5)
            ->and(CostReconciliation::query()->where('scope_id', $scope->id)->count())->toBe(1)
            ->and((string) $scope->current_reconciliation_id)->toBe($o['ok'][0])->and($scope->version)->toBe(1)
            ->and(AuditLog::where('action', 'cost.reconciled')->where('subject_id', $scope->id)->count())->toBe(1);

        // the same old pointer again ⇒ still stale (no silent second reconciliation); the refreshed pointer ⇒ a new explicit revision
        $again = e2Run(['reconcile', 'provider', $cp, '2026-08', 'USD', 'none', $line->id.':10.000000']);
        $again->wait();
        expect(trim($again->getOutput()))->toBe('stale')->and(CostReconciliation::query()->where('scope_id', $scope->id)->count())->toBe(1);
    } finally {
        e2Cleanup($cp);
    }
});

it('confirm: 6 concurrent confirmations from the same rendered token ⇒ 1 ok / 5 stale, one confirmed event, one audit', function () {
    $cp = e2Counterparty();
    $invoice = e2Invoice(['counterpartyKey' => $cp]);
    e2Line($invoice, ['amount' => '100.000000']);
    $token = $invoice->fresh()->stateToken();

    try {
        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = e2Run(['confirm', (string) $invoice->id, $token]);
        }
        $o = e2Outcome(e2Outcomes($processes));
        expect($o['ok'])->toHaveCount(1)->and($o['stale'])->toBe(5)
            ->and(CostInvoiceEvent::query()->where('cost_invoice_id', $invoice->id)->where('event_type', 'confirmed')->count())->toBe(1)
            ->and(AuditLog::where('action', 'cost_invoice.transitioned')->where('subject_id', $invoice->id)->count())->toBe(1);
    } finally {
        e2Cleanup($cp);
    }
});

it('add line: 6 concurrent submits of the same line_no ⇒ exactly one row (parent lock + unique (invoice, line_no)); the others are refused by the service rule line_no, never replayed', function () {
    $cp = e2Counterparty();
    $invoice = e2Invoice(['counterpartyKey' => $cp]);

    try {
        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = e2Run(['add-line', (string) $invoice->id, '1', 'service', 'api_usage', '100.000000']);
        }
        $o = e2Outcome(e2Outcomes($processes));
        expect($o['ok'])->toHaveCount(1, json_encode($o))->and($o['rejected'])->toBe(array_fill(0, 5, 'rejected:line_no'))
            ->and(CostInvoiceLine::query()->where('cost_invoice_id', $invoice->id)->count())->toBe(1)
            ->and(AuditLog::where('action', 'cost_invoice.line_added')->where('subject_id', $invoice->id)->count())->toBe(1);
    } finally {
        e2Cleanup($cp);
    }
});
