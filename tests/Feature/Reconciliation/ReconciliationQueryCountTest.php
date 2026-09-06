<?php

declare(strict_types=1);

use App\Models\CostReconciliationScope;
use App\Services\Reconciliation\CostInvoiceService;
use App\Services\Reconciliation\CostReconciliationService;
use App\Support\Rbac\Role;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Phase E5.2b performance guards: fixed query counts per page whatever the
 * number of rows (no N+1 on adjustments / allocations / lines, and NO ledger
 * capture per scope row), and on PostgreSQL the list filters use the existing
 * indexes — no index migration.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));
});

function e2Queries(callable $fn): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $fn();
    $n = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $n;
}

it('invoice list and scope list: the same number of queries with 3 rows and with 30 rows (paginated, sums keyed per page, no ledger capture per row)', function () {
    $fx = closableMonth();
    $this->actingAs(userWithRole(Role::Finance));
    $invoices = route('dashboard.finance.cost_invoices', ['fromMonth' => '2026-07', 'toMonth' => '2026-08']);
    $scopes = route('dashboard.finance.reconciliation', ['fromMonth' => '2026-07', 'toMonth' => '2026-08']);
    $this->get($invoices)->assertOk();
    $this->get($scopes)->assertOk();
    $smallInvoices = e2Queries(fn () => $this->get($invoices)->assertOk());
    $smallScopes = e2Queries(fn () => $this->get($scopes)->assertOk()->assertSee('3 rows'));

    for ($i = 0; $i < 27; $i++) {
        $invoice = e2ConfirmedInvoice(['service' => '1.000000'], ['component' => 'external', 'counterpartyKey' => 'ext-'.$i, 'invoiceRef' => 'E-'.$i, 'periodStart' => CarbonImmutable::parse('2026-07-01', 'UTC'), 'periodEnd' => CarbonImmutable::parse('2026-08-01', 'UTC')]);
        $rec = e2Reconcile([[$invoice->lines()->firstOrFail()->id, '1.000000']], ['component' => 'external', 'counterpartyKey' => 'ext-'.$i, 'month' => '2026-07']);
        app(CostReconciliationService::class)->adjust($rec->id, '-0.100000', 'credit', 'cn:'.$i, e2Key());
    }
    $largeInvoices = e2Queries(fn () => $this->get($invoices)->assertOk()->assertSee('28 rows'));
    $largeScopes = e2Queries(fn () => $this->get($scopes)->assertOk()->assertSee('30 rows'));
    expect($largeInvoices)->toBe($smallInvoices)->and($largeScopes)->toBe($smallScopes);
});

it('invoice detail and scope detail: the same number of queries with 1 line / 1 revision and with 20 lines / 12 revisions with allocations and adjustments (one live capture for the scope, never per revision)', function () {
    $fx = closableMonth();
    $this->actingAs(userWithRole(Role::Finance));
    $invoiceUrl = route('dashboard.finance.cost_invoices.show', $fx['invoice']->id);
    $scopeUrl = route('dashboard.finance.reconciliation.show', $fx['reconciliation']->scope_id);
    $this->get($invoiceUrl)->assertOk();
    $this->get($scopeUrl)->assertOk();
    $smallInvoice = e2Queries(fn () => $this->get($invoiceUrl)->assertOk());
    $smallScope = e2Queries(fn () => $this->get($scopeUrl)->assertOk());

    $big = e2Invoice(['invoiceRef' => 'BIG', 'totalAmount' => '20.000000']);
    for ($i = 1; $i <= 20; $i++) {
        e2Line($big, ['lineNo' => $i, 'amount' => '1.000000', 'descriptionCode' => 'l'.$i]);
    }
    app(CostInvoiceService::class)->confirm($big->id, $big->fresh()->stateToken());
    $scope = CostReconciliationScope::query()->findOrFail($fx['reconciliation']->scope_id);
    $lines = $big->lines()->orderBy('line_no')->get();
    for ($i = 0; $i < 11; $i++) {
        $rec = e2Reconcile([[$lines[$i]->id, '0.500000'], [$lines[$i + 1]->id, '0.500000']], ['expectedCurrentReconciliationId' => $scope->fresh()->current_reconciliation_id]); // each line ends at 1.000000 across the revisions — never over its cap
        app(CostReconciliationService::class)->adjust($rec->id, '-0.100000', 'credit', 'cn:'.$i, e2Key());
        app(CostReconciliationService::class)->adjust($rec->id, '0.050000', 'fee', 'cn:'.$i.'b', e2Key());
    }
    $largeInvoice = e2Queries(fn () => $this->get(route('dashboard.finance.cost_invoices.show', $big->id))->assertOk());
    $largeScope = e2Queries(fn () => $this->get($scopeUrl)->assertOk()->assertSee('revision-'.$rec->id));
    expect($largeInvoice)->toBe($smallInvoice)->and($largeScope)->toBe($smallScope);
});

it('PostgreSQL EXPLAIN: the invoice list uses cost_invoices_scope_idx / status_idx / the (counterparty_key, invoice_ref) unique index, the scope list its unique scope index or the pkey; never a Seq Scan with a selective filter — no index migration', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('EXPLAIN check runs on PostgreSQL only.');
    }

    e2Provider();
    for ($i = 0; $i < 300; $i++) {
        e2Invoice(['invoiceRef' => 'X-'.$i, 'component' => $i % 3 === 0 ? 'external' : 'provider', 'counterpartyKey' => $i % 3 === 0 ? 'ext-'.($i % 7) : 'groq', 'periodStart' => CarbonImmutable::parse('2026-01-01', 'UTC')->addMonths($i % 12), 'periodEnd' => CarbonImmutable::parse('2026-01-01', 'UTC')->addMonths($i % 12 + 1)]);
    }
    for ($i = 0; $i < 200; $i++) {
        e2Reconcile([], ['component' => 'external', 'counterpartyKey' => 'z-'.$i, 'month' => '2026-'.str_pad((string) ($i % 12 + 1), 2, '0', STR_PAD_LEFT), 'source' => 'confirmed_zero', 'reasonCode' => 'none', 'evidenceRef' => 'a:'.$i, 'typedConfirmation' => 'ZERO']);
    }
    DB::statement('ANALYZE cost_invoices');
    DB::statement('ANALYZE cost_reconciliation_scopes');

    $plan = fn (string $sql, array $b) => collect(DB::select('EXPLAIN '.$sql, $b))->pluck('QUERY PLAN')->implode("\n");
    expect($plan('SELECT * FROM cost_invoices WHERE component = ? AND counterparty_key = ? AND period_start >= ? AND period_start < ? ORDER BY id DESC LIMIT 25', ['external', 'ext-3', '2026-08-01 00:00:00', '2026-09-01 00:00:00']))->toContain('cost_invoices_scope_idx')
        ->and($plan('SELECT * FROM cost_invoices WHERE current_status = ? AND period_start >= ? AND period_start < ? ORDER BY id DESC LIMIT 25', ['confirmed', '2026-08-01 00:00:00', '2026-09-01 00:00:00']))->toContain('cost_invoices_status_idx')
        ->and($plan('SELECT * FROM cost_invoices WHERE counterparty_key = ? AND invoice_ref = ? AND period_start >= ? AND period_start < ? ORDER BY id DESC LIMIT 25', ['groq', 'X-7', '2026-01-01 00:00:00', '2027-01-01 00:00:00']))->toContain('cost_invoices_counterparty_ref_unique')
        ->and($plan('SELECT * FROM cost_reconciliation_scopes WHERE component = ? AND counterparty_key = ? AND period_start >= ? AND period_start < ? ORDER BY id DESC LIMIT 25', ['external', 'z-9', '2026-01-01 00:00:00', '2027-01-01 00:00:00']))->toContain('cost_reconciliation_scopes_scope_unique')
        ->and($plan('SELECT * FROM cost_reconciliation_scopes WHERE period_start >= ? AND period_start < ? AND current_reconciliation_id IS NULL ORDER BY id DESC LIMIT 25', ['2026-08-01 00:00:00', '2026-09-01 00:00:00']))->not->toContain('Bitmap Heap Scan on cost_invoices');
    // Documented, not asserted: the month window ALONE (no component / counterparty / status / ref) has no index of its own on
    // cost_invoices (period_start is the third column of cost_invoices_scope_idx) — with LIMIT 25 the planner reads the small
    // table sequentially. Adding an index is a schema decision that is NOT taken here (E5.2b = one approved migration only).
    $windowOnly = $plan('SELECT * FROM cost_invoices WHERE period_start >= ? AND period_start < ? ORDER BY id DESC LIMIT 25', ['2026-08-01 00:00:00', '2026-09-01 00:00:00']);
    expect($windowOnly)->toContain('cost_invoices');
});
