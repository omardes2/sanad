<?php

declare(strict_types=1);

use App\Services\Finance\FinanceExporter;
use App\Services\Finance\FinanceQuery;
use App\Services\Finance\MrrSnapshotHistory;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Phase D2 — every finance aggregation over the ledger carries a mandatory
 * occurred_at window: no code path can run an unbounded scan of usage_events.
 */
function ledgerQueriesDuring(callable $fn): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $fn();
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    return array_values(array_filter(array_map(static fn (array $q) => $q['query'], $log), static fn (string $sql) => str_contains($sql, 'usage_events')));
}

it('every FinanceQuery aggregation is bounded by the occurred_at window on both ends', function () {
    $finance = app(FinanceQuery::class);
    $from = CarbonImmutable::parse('2026-09-01', 'UTC');
    $to = CarbonImmutable::parse('2026-10-01', 'UTC');

    $queries = ledgerQueriesDuring(function () use ($finance, $from, $to): void {
        $query = $finance->build($from, $to);
        $finance->totals($query);
        $finance->byPlan($query);
        $finance->byProviderModel($query);
        $finance->byOperationChannel($query);
        $finance->topSubscribers($query, 5);
        $finance->trend($query, 'day');
        $finance->trend($finance->build($from, $to, [], FinanceQuery::MAX_TREND_DAYS), 'month');
    });

    expect(count($queries))->toBeGreaterThanOrEqual(8);

    foreach ($queries as $sql) {
        expect($sql)->toContain('"occurred_at" >= ?')->toContain('"occurred_at" < ?');
    }
});

it('the window cannot be omitted, reversed or wider than the bound', function () {
    $finance = app(FinanceQuery::class);
    $from = CarbonImmutable::parse('2026-01-01', 'UTC');

    expect((new ReflectionMethod(FinanceQuery::class, 'build'))->getParameters()[0]->allowsNull())->toBeFalse()
        ->and((new ReflectionMethod(FinanceQuery::class, 'build'))->getParameters()[1]->allowsNull())->toBeFalse()
        ->and(fn () => $finance->build($from, $from))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $finance->build($from->addDay(), $from))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $finance->build($from, $from->addDays(FinanceQuery::MAX_DAYS + 1)))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $finance->build($from, $from->addDays(FinanceQuery::MAX_TREND_DAYS + 1), [], FinanceQuery::MAX_TREND_DAYS))->toThrow(InvalidArgumentException::class);
});

it('the CSV export and the snapshot history are bounded too', function () {
    $from = CarbonImmutable::parse('2026-09-01', 'UTC');
    $to = CarbonImmutable::parse('2026-09-08', 'UTC');

    $queries = ledgerQueriesDuring(function () use ($from, $to): void {
        $response = app(FinanceExporter::class)->stream($from, $to, [], 'day', 5);
        ob_start();
        $response->sendContent();
        ob_end_clean();
    });

    // totals (2 statements) + 5 breakdowns/trend = 7 ledger statements.
    expect(count($queries))->toBe(7);
    foreach ($queries as $sql) {
        expect($sql)->toContain('"occurred_at" >= ?')->toContain('"occurred_at" < ?');
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    app(MrrSnapshotHistory::class)->series($from, $to);
    $snapshotQueries = array_filter(array_map(static fn (array $q) => $q['query'], DB::getQueryLog()), static fn (string $sql) => str_contains($sql, 'finance_mrr_snapshots') && ! str_contains($sql, 'min('));
    DB::disableQueryLog();

    expect($snapshotQueries)->not->toBeEmpty();
    foreach ($snapshotQueries as $sql) {
        expect($sql)->toContain('"snapshot_date" >= ?')->toContain('"snapshot_date" <= ?');
    }
});
