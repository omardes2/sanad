<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\FinancePeriodClose;
use App\Services\Close\ClosePreflight;
use App\Services\Close\PeriodCloseService;
use App\Services\Reconciliation\CostReconciliationService;
use App\Support\Rbac\Role;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Phase E5.1 performance guards: no preflight per historical close and no
 * N+1 — the close history and the frozen detail/export use a fixed number
 * of queries regardless of how many revisions or input rows exist, and a
 * frozen month in the overview costs no live evaluation at all.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));
});

function queriesDuring(callable $fn): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $fn();
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    return array_column($log, 'query');
}

/** Close, reopen, adjust, close again — n closed revisions for August. */
function revisions(array $fx, int $n): void
{
    $close = closeMonth('2026-08', null, 'rev-1');
    for ($i = 2; $i <= $n; $i++) {
        $reopen = app(PeriodCloseService::class)->reopen($close->id, $close->id, 'restatement', 'memo:'.$i, 'REOPEN 2026-08');
        app(CostReconciliationService::class)->adjust($fx['reconciliation']->id, '-1.000000', 'credit', 'cn:'.$i);
        $close = closeMonth('2026-08', $reopen->id, 'rev-'.$i);
    }
}

function preflightQueries(array $queries): int
{
    // Only ClosePreflight derives the expected providers: DISTINCT provider on usage_events bounded by occurred_at — one per evaluation.
    return count(array_filter($queries, fn (string $q) => str_contains($q, 'usage_events') && str_contains($q, 'distinct') && str_contains($q, 'occurred_at') && str_contains($q, 'provider')));
}

/** A throw-away request so permission / route caches are warm before counting. */
function warmUp(string $url): void
{
    test()->get($url)->assertOk();
}

it('close history: the number of queries does not grow with the number of revisions, and exactly one live evaluation happens (for the selected month, not per row)', function () {
    $fx = closableMonth();
    revisions($fx, 1);
    $user = userWithRole(Role::Finance);
    $this->actingAs($user);

    warmUp(route('dashboard.finance.close', ['month' => '2026-08']));
    $one = queriesDuring(fn () => $this->get(route('dashboard.finance.close', ['month' => '2026-08']))->assertOk());

    $this->actingAs(userWithRole(Role::SuperAdmin)); // reopen / close are super_admin only
    for ($i = 2; $i <= 4; $i++) {
        $current = FinancePeriodClose::query()->orderByDesc('id')->first();
        $reopen = app(PeriodCloseService::class)->reopen($current->id, $current->id, 'restatement', 'memo:'.$i, 'REOPEN 2026-08');
        app(CostReconciliationService::class)->adjust($fx['reconciliation']->id, '-1.000000', 'credit', 'cn:'.$i);
        closeMonth('2026-08', $reopen->id, 'rev-'.$i);
    }
    expect(FinancePeriodClose::count())->toBe(7); // 4 closes + 3 reopen records
    $this->actingAs($user);

    $four = queriesDuring(fn () => $this->get(route('dashboard.finance.close', ['month' => '2026-08']))->assertOk()->assertSee('FROZEN CLOSE REVISION 4')->assertSee('FROZEN CLOSE REVISION 1'));

    expect(count($four))->toBe(count($one))
        ->and(preflightQueries($four))->toBe(1)->and(preflightQueries($one))->toBe(1);
});

it('close detail and close export: a fixed number of queries whatever the number of input rows; no preflight at all', function () {
    $fx = closableMonth();
    $close = closeMonth('2026-08', null, 'k1');
    $user = userWithRole(Role::Finance);
    $this->actingAs($user);
    warmUp(route('dashboard.finance.close.show', $close->id));
    $this->get(route('dashboard.finance.close.export', $close->id))->assertOk()->streamedContent();
    $small = queriesDuring(fn () => $this->get(route('dashboard.finance.close.show', $close->id))->assertOk());
    $smallCsv = queriesDuring(fn () => $this->get(route('dashboard.finance.close.export', $close->id))->assertOk()->streamedContent());

    // A month with many more inputs: 30 extra payments (each with a fee) ⇒ 60 more input rows.
    $this->actingAs(userWithRole(Role::SuperAdmin));
    $reopen = app(PeriodCloseService::class)->reopen($close->id, $close->id, 'restatement', 'memo:1', 'REOPEN 2026-08');
    for ($i = 0; $i < 30; $i++) {
        e1Payment($fx['subscriber'], ['amount' => '1.00', 'currency' => 'USD', 'receivedAt' => CarbonImmutable::parse('2026-08-15', 'UTC')->addMinutes($i), 'gatewayFeeAmount' => '0.10', 'feeCurrency' => 'USD']);
    }
    $big = closeMonth('2026-08', $reopen->id, 'k2');
    expect($big->inputs()->count())->toBe(9 + 60);
    $this->actingAs($user);

    $large = queriesDuring(fn () => $this->get(route('dashboard.finance.close.show', $big->id))->assertOk());
    $largeCsv = queriesDuring(fn () => $this->get(route('dashboard.finance.close.export', $big->id))->assertOk()->streamedContent());

    expect(count($large))->toBe(count($small))->and(count($largeCsv))->toBe(count($smallCsv))
        ->and(preflightQueries($large))->toBe(0)->and(preflightQueries($largeCsv))->toBe(0)
        ->and(count($smallCsv))->toBeLessThanOrEqual(8);
});

it('overview: a frozen month costs no live evaluation; only months without a current close are evaluated, once each', function () {
    $fx = closableMonth();
    $user = userWithRole(Role::Finance);
    $this->actingAs($user);
    warmUp(route('dashboard.finance', ['from' => '2026-08-01', 'to' => '2026-09-06']));

    $open = queriesDuring(fn () => $this->get(route('dashboard.finance', ['from' => '2026-08-01', 'to' => '2026-09-06']))->assertOk());
    expect(preflightQueries($open))->toBe(2); // August + September live

    $this->actingAs(userWithRole(Role::SuperAdmin));
    closeMonth('2026-08', null, 'k1');
    $this->actingAs($user);
    $frozen = queriesDuring(fn () => $this->get(route('dashboard.finance', ['from' => '2026-08-01', 'to' => '2026-09-06']))->assertOk()->assertSee('FROZEN CLOSE REVISION 1'));
    expect(preflightQueries($frozen))->toBe(1); // September only; August comes from the close row
});

it('audit subject filter uses the (subject_type, subject_id) morph index on PostgreSQL — no new migration needed', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('EXPLAIN check runs on PostgreSQL only.');
    }

    // The index exists (created by nullableMorphs('subject') in the C0 migration) …
    $indexes = collect(DB::select("SELECT indexname FROM pg_indexes WHERE tablename = 'audit_logs'"))->pluck('indexname')->all();
    expect($indexes)->toContain('audit_logs_subject_type_subject_id_index');

    // … and the planner uses it for the page's exact query once the table has data (an empty table legitimately prefers the pkey scan).
    $target = 'App\\Models\\FinancePeriodCloseScope';
    AuditLog::factory()->count(300)->create(['subject_type' => 'App\\Models\\CustomerPayment', 'subject_id' => 7]);
    AuditLog::factory()->count(3)->create(['subject_type' => $target, 'subject_id' => 1]);
    DB::statement('ANALYZE audit_logs');
    $plan = collect(DB::select('EXPLAIN SELECT * FROM audit_logs WHERE subject_type = ? AND subject_id = ? ORDER BY id DESC LIMIT 25', [$target, 1]))->pluck('QUERY PLAN')->implode("\n");
    expect($plan)->toContain('audit_logs_subject_type_subject_id_index');
});
