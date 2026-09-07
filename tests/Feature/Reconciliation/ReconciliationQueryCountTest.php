<?php

declare(strict_types=1);

use App\Models\CostReconciliationScope;
use App\Services\Reconciliation\CostInvoiceService;
use App\Services\Reconciliation\CostReconciliationService;
use App\Support\Rbac\Role;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
});

/**
 * The exact SQL (with bindings) the invoice list issues for a month-window-only page: the paginator's count and the 25-row select.
 *
 * @return list<array{sql: string, bindings: array<int, mixed>}>
 */
function invoiceListStatements(string $month = '2026-08'): array
{
    $captured = [];
    DB::listen(function (QueryExecuted $q) use (&$captured): void {
        if (str_contains($q->sql, 'from "cost_invoices"') && str_contains($q->sql, 'period_start')) {
            $captured[] = ['sql' => $q->sql, 'bindings' => $q->bindings];
        }
    });
    test()->get(route('dashboard.finance.cost_invoices', ['fromMonth' => $month, 'toMonth' => $month]))->assertOk();

    return $captured;
}

/** 6,000 invoices over 12 months × 3 components × 40 counterparties, inserted in bulk (planner-realistic volume; no service, no audit). */
function seedInvoiceVolume(): void
{
    $components = ['provider', 'communication', 'external'];
    $rows = [];
    for ($i = 0; $i < 6000; $i++) {
        $month = CarbonImmutable::parse('2026-01-01', 'UTC')->addMonths(intdiv($i, 500)); // chronological: ids grow with the months, as real invoices do
        $rows[] = [
            'component' => $components[$i % 3], 'counterparty_key' => 'cp-'.($i % 40), 'invoice_ref' => 'V-'.$i, 'idempotency_key' => 'vol-'.$i,
            'issued_at' => $month->addDays($i % 28)->format('Y-m-d H:i:s'), 'period_start' => $month->format('Y-m-d H:i:s'), 'period_end' => $month->addMonth()->format('Y-m-d H:i:s'),
            'currency' => 'USD', 'total_amount' => '10.000000', 'current_status' => $i % 5 === 0 ? 'draft' : 'confirmed', 'recorded_by_ref' => 'seed', 'created_at' => now(), 'updated_at' => now(),
        ];
        if (count($rows) === 500) {
            DB::table('cost_invoices')->insert($rows);
            $rows = [];
        }
    }
}

it('realistic PostgreSQL EXPLAIN on the page\'s own pagination SQL (6,000 invoices): month-window-only ⇒ Seq Scan WITHOUT the (period_start, id) index and an index plan WITH it; currency / issued_at stay unindexed', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('EXPLAIN check runs on PostgreSQL only.');
    }

    seedInvoiceVolume();
    $this->actingAs(userWithRole(Role::Finance));
    $statements = invoiceListStatements('2026-01'); // the OLDEST month: the worst case for an id-ordered walk that has to skip newer rows
    expect($statements)->toHaveCount(2)
        ->and($statements[0]['sql'])->toStartWith('select count(*) as')
        ->and($statements[1]['sql'])->toContain('order by "period_start" desc, "id" desc limit 25');
    $plan = fn (array $st) => collect(DB::select('EXPLAIN (ANALYZE, COSTS OFF, TIMING OFF, SUMMARY OFF) '.$st['sql'], $st['bindings']))->pluck('QUERY PLAN')->implode("\n");
    $removed = fn (string $plan) => (int) (preg_match('/Rows Removed by Filter: (\d+)/', $plan, $m) === 1 ? $m[1] : 0);

    // BEFORE: without the E5.2b index (rolled back inside this transaction) the count scans the whole table and the page walks the
    // primary key backwards discarding thousands of newer rows before it finds 25 of the requested month — neither uses an index on the window
    Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);
    DB::statement('ANALYZE cost_invoices');
    expect(Schema::hasIndex('cost_invoices', 'cost_invoices_period_start_id_idx'))->toBeFalse();
    $beforeCount = $plan($statements[0]);
    $beforePage = $plan($statements[1]);
    expect($beforeCount)->toContain('Seq Scan on cost_invoices')
        ->and($beforePage)->toMatch('/Seq Scan on cost_invoices|Index Scan Backward using cost_invoices_pkey/')
        ->and($removed($beforePage))->toBeGreaterThan(5000, $beforePage) // 6,000 rows, 500 in January: the walk discards the other months first
        ->and($removed($beforeCount))->toBeGreaterThan(5000, $beforeCount);

    // AFTER: with the index, both statements are index-backed on cost_invoices_period_start_id_idx — no sequential scan, no filter discards
    Artisan::call('migrate', ['--force' => true]);
    DB::statement('ANALYZE cost_invoices');
    $afterCount = $plan($statements[0]);
    $afterPage = $plan($statements[1]);
    expect($afterCount)->toContain('cost_invoices_period_start_id_idx')->not->toContain('Seq Scan')
        ->and($afterPage)->toContain('cost_invoices_period_start_id_idx')->not->toContain('Seq Scan')->not->toContain('Index Scan Backward using cost_invoices_pkey')
        ->and($afterPage)->toMatch('/(Index Scan|Index Only Scan|Bitmap Index Scan)/')
        ->and($removed($afterPage))->toBe(0, $afterPage)
        ->and($removed($afterCount))->toBe(0, $afterCount);
    fwrite(STDERR, "\n[EXPLAIN before/count]\n{$beforeCount}\n[EXPLAIN before/page]\n{$beforePage}\n[EXPLAIN after/count]\n{$afterCount}\n[EXPLAIN after/page]\n{$afterPage}\n");

    // no index on currency or issued_at (not approved): a currency-only or issued_at-only predicate stays a table scan, and the list never offers them as primary filters
    $indexes = collect(Schema::getIndexes('cost_invoices'))->pluck('columns')->map(fn ($c) => implode(',', $c))->all();
    expect($indexes)->not->toContain('currency')->not->toContain('issued_at');
});

it('SQLite plan: the month-window-only count and page statements use cost_invoices_period_start_id_idx (on PostgreSQL the realistic EXPLAIN above is the proof; the index presence is asserted here)', function () {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        expect(Schema::hasIndex('cost_invoices', 'cost_invoices_period_start_id_idx'))->toBeTrue();

        return;
    }

    e2Provider();
    for ($i = 0; $i < 30; $i++) {
        e2Invoice(['invoiceRef' => 'S-'.$i]);
    }
    $this->actingAs(userWithRole(Role::Finance));
    $statements = invoiceListStatements();
    $plan = fn (array $st) => collect(DB::select('EXPLAIN QUERY PLAN '.$st['sql'], $st['bindings']))->pluck('detail')->implode("\n");
    expect($statements)->toHaveCount(2)
        ->and($plan($statements[0]))->toContain('USING COVERING INDEX cost_invoices_period_start_id_idx')
        ->and($plan($statements[1]))->toContain('USING INDEX cost_invoices_period_start_id_idx')->not->toContain('TEMP B-TREE'); // the index serves the range AND the order
});
