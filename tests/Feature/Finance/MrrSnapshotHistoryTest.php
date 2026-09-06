<?php

declare(strict_types=1);

use App\Data\Finance\MrrHistoryDay;
use App\Data\Finance\MrrHistorySeries;
use App\Models\FinanceMrrSnapshot;
use App\Services\Finance\MrrSnapshotHistory;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Phase D2 — the MRR SNAPSHOT HISTORY is a run-rate series: NOT AVAILABLE
 * before the first snapshot, NOT CAPTURED for a missing day, markers excluded,
 * never interpolated, never summed into revenue.
 */
function snapshotRow(string $date, string $currency, string $planKey, string $mrr, int $active = 1, array $extra = []): FinanceMrrSnapshot
{
    return FinanceMrrSnapshot::create(array_merge([
        'snapshot_date' => $date, 'captured_at' => CarbonImmutable::parse($date.' 03:00:00', 'UTC'), 'currency' => $currency,
        'plan_id' => $planKey === 'none' ? null : (int) substr($planKey, 5), 'plan_key' => $planKey, 'plan_slug' => $planKey === 'none' ? null : 'p',
        'plan_price' => $planKey === 'none' ? null : '10.00', 'billing_period' => $planKey === 'none' ? null : 'monthly',
        'active_count' => $active, 'trialing_count' => 0, 'past_due_count' => 0, 'mrr_normalized' => $mrr, 'calculation_version' => 1,
    ], $extra));
}

it('marks days before the first snapshot NOT AVAILABLE, missing days NOT CAPTURED, and excludes marker rows', function () {
    snapshotRow('2026-09-03', 'USD', 'plan:1', '20.000000', 2);
    snapshotRow('2026-09-03', 'USD', 'plan:2', '8.333333', 1);
    snapshotRow('2026-09-03', 'ILS', 'plan:3', '35.000000', 1);
    snapshotRow('2026-09-03', 'XXX', 'none', '0.000000', 5); // marker: no plan
    // 2026-09-04 never captured.
    snapshotRow('2026-09-05', 'USD', 'plan:1', '30.000000', 3, ['past_due_count' => 1]);
    snapshotRow('2026-09-06', 'XXX', 'none', '0.000000', 0); // empty-day marker only

    $series = app(MrrSnapshotHistory::class)->series(CarbonImmutable::parse('2026-09-01', 'UTC'), CarbonImmutable::parse('2026-09-06', 'UTC'));

    $byDate = collect($series->days)->keyBy('date');

    expect($series->firstSnapshotDate)->toBe('2026-09-03')
        ->and($series->currencies)->toBe(['ILS', 'USD']) // XXX is never a currency
        ->and(array_keys($byDate->all()))->toBe(['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04', '2026-09-05', '2026-09-06'])
        ->and($byDate['2026-09-01']->status)->toBe(MrrHistoryDay::NOT_AVAILABLE)
        ->and($byDate['2026-09-02']->status)->toBe(MrrHistoryDay::NOT_AVAILABLE)
        ->and($byDate['2026-09-03']->status)->toBe(MrrHistoryDay::CAPTURED)
        ->and($byDate['2026-09-03']->byCurrency)->toBe([
            'ILS' => ['mrr' => '35.000000', 'active' => 1, 'trialing' => 0, 'past_due' => 0],
            'USD' => ['mrr' => '28.333333', 'active' => 3, 'trialing' => 0, 'past_due' => 0],
        ])
        ->and($byDate['2026-09-04']->status)->toBe(MrrHistoryDay::NOT_CAPTURED) // no interpolation between 09-03 and 09-05
        ->and($byDate['2026-09-04']->byCurrency)->toBe([])
        ->and($byDate['2026-09-05']->byCurrency['USD'])->toBe(['mrr' => '30.000000', 'active' => 3, 'trialing' => 0, 'past_due' => 1])
        ->and($byDate['2026-09-06']->status)->toBe(MrrHistoryDay::CAPTURED) // captured, nothing subscribed
        ->and($byDate['2026-09-06']->byCurrency)->toBe([])
        ->and($series->counts())->toBe(['captured' => 3, 'not_captured' => 1, 'not_available' => 2]);
});

it('is NOT AVAILABLE for every day when no snapshot exists at all', function () {
    $series = app(MrrSnapshotHistory::class)->series(CarbonImmutable::parse('2026-09-01', 'UTC'), CarbonImmutable::parse('2026-09-03', 'UTC'));

    expect($series->hasAnySnapshot())->toBeFalse()
        ->and(collect($series->days)->pluck('status')->unique()->all())->toBe([MrrHistoryDay::NOT_AVAILABLE]);
});

it('exposes no revenue aggregate: the series has no sum, no day-multiplication and no cost', function () {
    $methods = array_map(static fn (ReflectionMethod $m) => $m->getName(), (new ReflectionClass(MrrHistorySeries::class))->getMethods(ReflectionMethod::IS_PUBLIC));

    expect($methods)->not->toContain('total')->not->toContain('sum')->not->toContain('revenue')->not->toContain('grossProfit')->not->toContain('margin');

    $source = file_get_contents(app_path('Services/Finance/MrrSnapshotHistory.php'));
    expect($source)->not->toContain('total_cost')->not->toContain('usage_events')->not->toContain('UsageEvent');
});

it('requires a bounded window on snapshot_date', function () {
    $history = app(MrrSnapshotHistory::class);
    $from = CarbonImmutable::parse('2026-01-01', 'UTC');

    expect(fn () => $history->series($from->addDay(), $from))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $history->series($from, $from->addDays(367)))->toThrow(InvalidArgumentException::class)
        ->and($history->series($from, $from)->days)->toHaveCount(1);
});
