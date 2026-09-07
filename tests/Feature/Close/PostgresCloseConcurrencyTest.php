<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\CostReconciliation;
use App\Models\CostReconciliationScope;
use App\Models\FinancePeriodClose;
use App\Models\FinancePeriodCloseInput;
use App\Models\FinancePeriodCloseScope;
use App\Support\Reconciliation\ReconciliationRules;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * GENUINE parallel tests for Phase E4 on PostgreSQL (separate PHP processes):
 *  - 6 concurrent closes of one month from the same pointer ⇒ one close,
 *    five stale, one pointer move, one audit, one set of input rows;
 *  - 6 concurrent replays of the same idempotency key ⇒ one close, and every
 *    replay returns that same close (key re-checked under the scope lock);
 *  - 6 concurrent reopens of the current close ⇒ one reopen, five stale.
 * Runs only on a reachable pgsql connection; cleans only its own rows.
 * The month is chosen in a year no other test uses so the shared database
 * stays independent.
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

function closeRun(array $args): Process
{
    $p = new Process(['php', 'artisan', 'sanad:close-probe', ...$args], base_path());
    $p->start();

    return $p;
}

/** @return list<string> */
function closeOutcomes(array $processes): array
{
    $outcomes = [];
    foreach ($processes as $p) {
        $p->wait();
        expect($p->getExitCode())->toBe(0, $p->getOutput().$p->getErrorOutput());
        $outcomes[] = trim($p->getOutput());
    }

    return $outcomes;
}

/** An empty, closable month: no cash, and CONFIRMED ZERO for every component (no ledger rows ⇒ no expected providers). */
function emptyClosableMonth(string $month): array
{
    $keys = [];
    foreach (['provider' => null, 'communication' => 'pgrace-comm', 'external' => 'pgrace-ext'] as $component => $cp) {
        if ($cp === null) {
            continue;
        }
        $keys[] = $cp = $cp.'-'.strtolower(str()->random(4));
        e2Reconcile([], ['component' => $component, 'counterpartyKey' => $cp, 'month' => $month, 'source' => 'confirmed_zero', 'reasonCode' => 'none', 'evidenceRef' => 'att', 'typedConfirmation' => 'ZERO']);
    }

    return $keys;
}

function closeCleanup(string $month, array $counterparties): void
{
    [$start] = ReconciliationRules::month($month);
    $scopeIds = FinancePeriodCloseScope::query()->where('period_start', $start->format('Y-m-d H:i:s'))->pluck('id');
    $closeIds = FinancePeriodClose::query()->whereIn('scope_id', $scopeIds)->pluck('id');
    DB::table('finance_period_close_inputs')->whereIn('close_id', $closeIds)->delete();
    DB::table('finance_period_close_scopes')->whereIn('id', $scopeIds)->update(['current_close_id' => null]);
    DB::table('finance_period_closes')->whereIn('id', $closeIds)->delete();
    AuditLog::where('subject_type', (new FinancePeriodCloseScope)->getMorphClass())->whereIn('subject_id', $scopeIds)->delete();
    DB::table('finance_period_close_scopes')->whereIn('id', $scopeIds)->delete();

    $recScopeIds = CostReconciliationScope::query()->whereIn('counterparty_key', $counterparties)->pluck('id');
    $recIds = CostReconciliation::query()->whereIn('scope_id', $recScopeIds)->pluck('id');
    DB::table('cost_reconciliation_scopes')->whereIn('id', $recScopeIds)->update(['current_reconciliation_id' => null]);
    DB::table('cost_reconciliations')->whereIn('id', $recIds)->delete();
    AuditLog::where('subject_type', (new CostReconciliationScope)->getMorphClass())->whereIn('subject_id', $recScopeIds)->delete();
    DB::table('cost_reconciliation_scopes')->whereIn('id', $recScopeIds)->delete();
}

it('of 6 concurrent closes from the same pointer exactly one closes the month; five are stale; one close, one input set, one pointer move, one audit', function () {
    $month = '2024-03';
    $cps = emptyClosableMonth($month);

    try {
        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = closeRun(['close', $month, 'none', 'race-close-'.$i.'-'.str()->random(4)]);
        }
        $outcomes = closeOutcomes($processes);
        $scope = FinancePeriodCloseScope::query()->where('period_start', '2024-03-01 00:00:00')->firstOrFail();
        $winners = array_values(array_filter($outcomes, fn ($o) => str_starts_with($o, 'ok:')));
        $winnerId = (int) explode(':', $winners[0] ?? 'ok:0')[1];

        expect($winners)->toHaveCount(1)
            ->and(array_filter($outcomes, fn ($o) => $o === 'stale'))->toHaveCount(5)
            ->and(FinancePeriodClose::query()->where('scope_id', $scope->id)->count())->toBe(1)
            ->and('ok:'.$scope->current_close_id)->toBe($winners[0])
            ->and($scope->state)->toBe('closed')->and($scope->version)->toBe(1)
            ->and(FinancePeriodCloseInput::query()->where('close_id', $scope->current_close_id)->count())->toBe(2) // the two CONFIRMED ZERO reconciliations
            ->and(AuditLog::where('action', 'finance.period_closed')->where('subject_id', $scope->id)->count())->toBe(1)
            ->and((string) FinancePeriodClose::query()->find($scope->current_close_id)->reconciled_cash_contribution)->toBe('0.000000');

        // Idempotency race: six replays of one key on the reopened month ⇒ one close.
        $reopen = closeRun(['reopen', (string) $scope->current_close_id, (string) $scope->current_close_id, $month, e4Key()]);
        $reopen->wait();
        expect(trim($reopen->getOutput()))->toStartWith('ok:');
        $scope->refresh();
        $key = 'race-key-'.str()->random(6);
        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = closeRun(['close', $month, (string) $scope->current_close_id, $key]);
        }
        $outcomes = closeOutcomes($processes);
        $v2 = FinancePeriodClose::query()->where('idempotency_key', $key)->firstOrFail();
        expect(array_values(array_unique($outcomes)))->toBe(['ok:'.$v2->id]) // every replay returns the SAME close: the key is re-checked under the scope lock
            ->and(FinancePeriodClose::query()->where('scope_id', $scope->id)->where('status', 'closed')->count())->toBe(2) // revision 1 + revision 2
            ->and($v2->revision)->toBe(2)->and($v2->previous_close_id)->toBe($winnerId)
            ->and($scope->fresh()->current_close_id)->toBe($v2->id)->and($scope->fresh()->state)->toBe('closed')
            ->and(AuditLog::where('action', 'finance.period_closed')->where('subject_id', $scope->id)->count())->toBe(2);
    } finally {
        closeCleanup($month, $cps);
    }
});

it('of 6 concurrent reopens of the current close with DIFFERENT keys exactly one wins; five are stale; the old close is untouched', function () {
    $month = '2024-04';
    $cps = emptyClosableMonth($month);

    try {
        $first = closeRun(['close', $month, 'none', 'race-reopen-'.str()->random(4)]);
        $first->wait();
        $closeId = (int) explode(':', trim($first->getOutput()))[1];
        $close = FinancePeriodClose::query()->findOrFail($closeId);
        $hash = $close->input_hash;

        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = closeRun(['reopen', (string) $closeId, (string) $closeId, $month, e4Key()]); // a DIFFERENT key each: six genuinely different requests
        }
        $outcomes = closeOutcomes($processes);
        $scope = FinancePeriodCloseScope::query()->whereKey($close->scope_id)->firstOrFail();

        expect(array_filter($outcomes, fn ($o) => str_starts_with($o, 'ok:')))->toHaveCount(1)
            ->and(array_filter($outcomes, fn ($o) => $o === 'stale'))->toHaveCount(5)
            ->and(FinancePeriodClose::query()->where('scope_id', $scope->id)->where('status', 'reopened')->count())->toBe(1)
            ->and($scope->state)->toBe('open')->and($scope->version)->toBe(2)
            ->and($close->fresh()->input_hash)->toBe($hash)->and($close->fresh()->status->value)->toBe('closed')
            ->and(AuditLog::where('action', 'finance.period_reopened')->where('subject_id', $scope->id)->count())->toBe(1);
    } finally {
        closeCleanup($month, $cps);
    }
});

it('of 6 concurrent reopens carrying the SAME key exactly one row is written and every process returns it: one reopen, one pointer move, one audit (E5.2c)', function () {
    $month = '2024-05';
    $cps = emptyClosableMonth($month);

    try {
        $first = closeRun(['close', $month, 'none', 'race-same-key-'.str()->random(4)]);
        $first->wait();
        $closeId = (int) explode(':', trim($first->getOutput()))[1];
        $close = FinancePeriodClose::query()->findOrFail($closeId);
        $key = 'reopen-key-'.str()->random(8);

        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = closeRun(['reopen', (string) $closeId, (string) $closeId, $month, $key]);
        }
        $outcomes = closeOutcomes($processes);
        $scope = FinancePeriodCloseScope::query()->whereKey($close->scope_id)->firstOrFail();
        $reopen = FinancePeriodClose::query()->where('idempotency_key', $key)->firstOrFail();

        // Exactly one writer; the other five replay the same key with the same facts and get the SAME row back.
        expect(array_filter($outcomes, fn ($o) => $o === 'ok:'.$reopen->id))->toHaveCount(1)
            ->and(array_filter($outcomes, fn ($o) => $o === 'existing:'.$reopen->id))->toHaveCount(5)
            ->and(FinancePeriodClose::query()->where('scope_id', $scope->id)->where('status', 'reopened')->count())->toBe(1)
            ->and($reopen->reopened_close_id)->toBe($closeId)
            ->and($scope->state)->toBe('open')->and($scope->version)->toBe(2)->and($scope->current_close_id)->toBe($reopen->id)
            ->and($close->fresh()->status->value)->toBe('closed')
            ->and(AuditLog::where('action', 'finance.period_reopened')->where('subject_id', $scope->id)->count())->toBe(1);
    } finally {
        closeCleanup($month, $cps);
    }
});

it('a historical reopen replayed after the month was closed again returns its recorded row and never moves the pointer backwards (E5.2c)', function () {
    $month = '2024-06';
    $cps = emptyClosableMonth($month);

    try {
        $v1 = closeRun(['close', $month, 'none', 'hist-close-1-'.str()->random(4)]);
        $v1->wait();
        $v1Id = (int) explode(':', trim($v1->getOutput()))[1];
        $key = 'reopen-hist-'.str()->random(8);

        $reopen = closeRun(['reopen', (string) $v1Id, (string) $v1Id, $month, $key]);
        $reopen->wait();
        $reopenId = (int) explode(':', trim($reopen->getOutput()))[1];

        $v2 = closeRun(['close', $month, (string) $reopenId, 'hist-close-2-'.str()->random(4)]);
        $v2->wait();
        $v2Id = (int) explode(':', trim($v2->getOutput()))[1];

        $scope = FinancePeriodCloseScope::query()->whereKey(FinancePeriodClose::query()->findOrFail($v2Id)->scope_id)->firstOrFail();
        expect($scope->current_close_id)->toBe($v2Id)->and($scope->state)->toBe('closed');

        // The old reopen is replayed (a retried request, a queued job, a stuck browser tab): same key, same facts.
        $replay = closeRun(['reopen', (string) $v1Id, (string) $v1Id, $month, $key]);
        $replay->wait();

        expect(trim($replay->getOutput()))->toBe('existing:'.$reopenId)
            ->and(FinancePeriodClose::query()->where('scope_id', $scope->id)->count())->toBe(3) // v1 + reopen + v2, nothing new
            ->and($scope->fresh()->current_close_id)->toBe($v2Id) // the pointer NEVER goes back to the reopen record
            ->and($scope->fresh()->state)->toBe('closed')
            ->and($scope->fresh()->version)->toBe(3)
            ->and(AuditLog::where('action', 'finance.period_reopened')->where('subject_id', $scope->id)->count())->toBe(1);
    } finally {
        closeCleanup($month, $cps);
    }
});
