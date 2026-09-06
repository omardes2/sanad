<?php

declare(strict_types=1);

use App\Models\AiProvider;
use App\Models\AuditLog;
use App\Models\CostInvoice;
use App\Models\CostInvoiceAllocation;
use App\Models\CostInvoiceEvent;
use App\Models\CostReconciliation;
use App\Models\CostReconciliationScope;
use App\Services\Reconciliation\CostReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * GENUINE parallel tests for Phase E2 on PostgreSQL (separate PHP processes):
 *  - invoice idempotency race: 6 recorders of the same key ⇒ one invoice,
 *    the others receive it; different facts ⇒ conflict;
 *  - confirmation race: 6 confirmations from the same token ⇒ exactly one
 *    confirmed event, five stale;
 *  - evidence allocation race: one 100 service line, six monthly
 *    reconciliations each asking 30 ⇒ exactly three succeed, |Σ| ≤ 100, no
 *    clipping — and the same for a −100 credit line;
 *  - reconciliation race: 6 reconciliations of ONE scope from the same
 *    expected pointer ⇒ one succeeds, five stale, one reconciliation, one
 *    pointer move, one audit; the refreshed loser supersedes.
 * Runs only on a reachable pgsql connection; cleans only its own rows
 * (query builder — the models refuse deletes by design).
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

function e2Run(array $args): Process
{
    $p = new Process(['php', 'artisan', 'sanad:reconciliation-probe', ...$args], base_path());
    $p->start();

    return $p;
}

/** @return list<string> */
function e2Outcomes(array $processes): array
{
    $outcomes = [];
    foreach ($processes as $p) {
        $p->wait();
        expect($p->getExitCode())->toBe(0, $p->getOutput().$p->getErrorOutput());
        $outcomes[] = trim($p->getOutput());
    }

    return $outcomes;
}

function e2Counterparty(): string
{
    $key = 'pgrace-'.strtolower(str()->random(6));
    AiProvider::factory()->create(['key' => $key, 'driver' => 'groq', 'priority' => 1]);

    return $key;
}

function e2Cleanup(string $counterparty): void
{
    $invoiceIds = CostInvoice::query()->where('counterparty_key', $counterparty)->pluck('id');
    $scopeIds = CostReconciliationScope::query()->where('counterparty_key', $counterparty)->pluck('id');
    $reconciliationIds = CostReconciliation::query()->whereIn('scope_id', $scopeIds)->pluck('id');
    DB::table('cost_adjustments')->whereIn('cost_reconciliation_id', $reconciliationIds)->delete();
    DB::table('cost_invoice_allocations')->whereIn('cost_reconciliation_id', $reconciliationIds)->orWhereIn('cost_invoice_id', $invoiceIds)->delete();
    DB::table('cost_reconciliation_scopes')->whereIn('id', $scopeIds)->update(['current_reconciliation_id' => null]);
    DB::table('cost_reconciliations')->whereIn('id', $reconciliationIds)->delete();
    DB::table('cost_reconciliation_scopes')->whereIn('id', $scopeIds)->delete();
    DB::table('cost_invoice_lines')->whereIn('cost_invoice_id', $invoiceIds)->delete();
    DB::table('cost_invoice_events')->whereIn('cost_invoice_id', $invoiceIds)->delete();
    AuditLog::where('subject_type', (new CostInvoice)->getMorphClass())->whereIn('subject_id', $invoiceIds)->delete();
    AuditLog::where('subject_type', (new CostReconciliationScope)->getMorphClass())->whereIn('subject_id', $scopeIds)->delete();
    DB::table('cost_invoices')->whereIn('id', $invoiceIds)->delete();
    DB::table('ai_providers')->where('key', $counterparty)->delete();
}

it('of 6 concurrent recordings of the same invoice key exactly one creates the invoice; the others receive it; different facts conflict', function () {
    $cp = e2Counterparty();
    $key = 'inv-race-'.str()->random(6);

    try {
        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = e2Run(['record-invoice', 'provider', $cp, $key, '100.000000', 'USD', 'REF-'.$key]);
        }
        $outcomes = e2Outcomes($processes);
        $invoices = CostInvoice::query()->where('counterparty_key', $cp)->get();

        expect(array_filter($outcomes, fn ($o) => str_starts_with($o, 'created:')))->toHaveCount(1)
            ->and(array_filter($outcomes, fn ($o) => str_starts_with($o, 'existing:')))->toHaveCount(5)
            ->and($invoices)->toHaveCount(1)
            ->and(array_unique(array_map(fn ($o) => explode(':', $o)[1], $outcomes)))->toBe([(string) $invoices[0]->id])
            ->and(CostInvoiceEvent::query()->where('cost_invoice_id', $invoices[0]->id)->count())->toBe(1)
            ->and(AuditLog::where('subject_type', (new CostInvoice)->getMorphClass())->where('subject_id', $invoices[0]->id)->count())->toBe(1);

        $conflict = e2Run(['record-invoice', 'provider', $cp, $key, '100.000001', 'USD', 'REF-'.$key]);
        $conflict->wait();
        $sameRef = e2Run(['record-invoice', 'provider', $cp, 'other-'.$key, '100.000000', 'USD', 'REF-'.$key]);
        $sameRef->wait();
        expect(trim($conflict->getOutput()))->toBe('conflict')->and(trim($sameRef->getOutput()))->toBe('conflict')
            ->and(CostInvoice::query()->where('counterparty_key', $cp)->count())->toBe(1);
    } finally {
        e2Cleanup($cp);
    }
});

it('of 6 concurrent confirmations from the same token exactly one confirms (one confirmed event, one audit); five are stale', function () {
    $cp = e2Counterparty();
    $invoice = e2Invoice(['counterpartyKey' => $cp]);
    e2Line($invoice, ['amount' => '100.000000']);
    $token = $invoice->fresh()->stateToken();

    try {
        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = e2Run(['confirm', (string) $invoice->id, $token]);
        }
        $outcomes = e2Outcomes($processes);

        expect(array_filter($outcomes, fn ($o) => str_starts_with($o, 'ok:')))->toHaveCount(1)
            ->and(array_filter($outcomes, fn ($o) => $o === 'stale'))->toHaveCount(5)
            ->and(CostInvoiceEvent::query()->where('cost_invoice_id', $invoice->id)->where('event_type', 'confirmed')->count())->toBe(1)
            ->and($invoice->fresh()->isConfirmed())->toBeTrue()
            ->and(AuditLog::where('action', 'cost_invoice.transitioned')->where('subject_id', $invoice->id)->count())->toBe(1);
    } finally {
        e2Cleanup($cp);
    }
});

it('of 6 concurrent monthly reconciliations drawing 30 from one 100 service line exactly three succeed and three are refused in full; a −100 credit line behaves the same with −30', function () {
    $cp = e2Counterparty();
    $invoice = e2ConfirmedInvoice(['service' => '100.000000', 'credit' => '-100.000000'], ['counterpartyKey' => $cp, 'periodStart' => CarbonImmutable::parse('2026-01-01', 'UTC'), 'periodEnd' => CarbonImmutable::parse('2026-07-01', 'UTC')]);
    [$service, $credit] = $invoice->lines()->orderBy('line_no')->get()->all();

    try {
        // Six fresh scopes per round (a scope that already has a reconciliation would answer "stale" to expected = none).
        foreach ([[$service, '30.000000', 30000000, 2026], [$credit, '-30.000000', -30000000, 2025]] as [$line, $amount, $each, $year]) {
            $processes = [];
            foreach (['01', '02', '03', '04', '05', '06'] as $mm) {
                $month = $year.'-'.$mm;
                $processes[] = e2Run(['reconcile', 'provider', $cp, $month, 'USD', 'none', $line->id.':'.$amount]);
            }
            $outcomes = e2Outcomes($processes);
            $allocations = CostInvoiceAllocation::query()->where('cost_invoice_line_id', $line->id)->get();

            expect(array_filter($outcomes, fn ($o) => str_starts_with($o, 'ok:')))->toHaveCount(3, $amount)
                ->and(array_filter($outcomes, fn ($o) => $o === 'rejected:allocation_limit'))->toHaveCount(3, $amount)
                ->and($allocations)->toHaveCount(3)
                ->and($allocations->pluck('amount')->map(fn ($a) => (string) $a)->unique()->all())->toBe([$amount]) // never clipped
                ->and($allocations->sum(fn ($a) => CostReconciliationService::scaledOf((string) $a->amount)))->toBe(3 * $each); // |Σ| = 90 ≤ 100

            // Each month's reconciliation equals its own allocation; scopes were created concurrently without duplicates.
            expect(CostReconciliation::query()->whereIn('id', $allocations->pluck('cost_reconciliation_id'))->pluck('reconciled_amount')->map(fn ($a) => (string) $a)->unique()->all())->toBe([$amount]);
        }

        // 3 scopes per round: a refused reconciliation rolls its scope row back too — nothing is left behind.
        expect(CostReconciliationScope::query()->where('counterparty_key', $cp)->count())->toBe(6);
    } finally {
        e2Cleanup($cp);
    }
});

it('of 6 concurrent reconciliations of ONE scope from the same expected pointer exactly one succeeds; five are stale; one pointer move; one audit; the refreshed loser supersedes', function () {
    $cp = e2Counterparty();
    $invoice = e2ConfirmedInvoice(['service' => '600.000000'], ['counterpartyKey' => $cp]);
    $line = $invoice->lines()->firstOrFail();

    try {
        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = e2Run(['reconcile', 'provider', $cp, '2026-08', 'USD', 'none', $line->id.':10.000000']);
        }
        $outcomes = e2Outcomes($processes);
        $scope = CostReconciliationScope::query()->where('counterparty_key', $cp)->firstOrFail();
        $winners = array_values(array_filter($outcomes, fn ($o) => str_starts_with($o, 'ok:')));

        expect($winners)->toHaveCount(1)
            ->and(array_filter($outcomes, fn ($o) => $o === 'stale'))->toHaveCount(5)
            ->and(CostReconciliationScope::query()->where('counterparty_key', $cp)->count())->toBe(1)
            ->and(CostReconciliation::query()->where('scope_id', $scope->id)->count())->toBe(1)
            ->and('ok:'.$scope->current_reconciliation_id)->toBe($winners[0])
            ->and($scope->version)->toBe(1)
            ->and(AuditLog::where('action', 'cost.reconciled')->where('subject_id', $scope->id)->count())->toBe(1);

        // A loser refreshes, sees the winner's id and supersedes it.
        $retry = e2Run(['reconcile', 'provider', $cp, '2026-08', 'USD', (string) $scope->current_reconciliation_id, $line->id.':20.000000']);
        $retry->wait();
        $scope->refresh();
        expect(trim($retry->getOutput()))->toBe('ok:'.$scope->current_reconciliation_id)
            ->and(CostReconciliation::query()->where('scope_id', $scope->id)->count())->toBe(2)
            ->and(CostReconciliation::query()->find($scope->current_reconciliation_id)->supersedes_id)->toBe((int) explode(':', $winners[0])[1])
            ->and($scope->version)->toBe(2);
    } finally {
        e2Cleanup($cp);
    }
});
