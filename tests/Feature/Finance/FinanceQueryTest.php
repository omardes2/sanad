<?php

declare(strict_types=1);

use App\Data\Finance\CostBucket;
use App\Enums\CostSource;
use App\Enums\CoverageStatus;
use App\Models\UsageEvent;
use App\Models\User;
use App\Services\Finance\FinanceQuery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Phase D1 — the finance aggregation contract on the ledger, verified with a
 * deterministic fixture whose expected figures are exact decimal strings so the
 * SAME test proves parity on SQLite (fast suite) and PostgreSQL (CI job).
 */

/**
 * @param  array<string, mixed>  $attrs
 */
function financeRow(array $attrs): UsageEvent
{
    $cost = (string) ($attrs['total_cost'] ?? '0.000000');

    return UsageEvent::factory()->create(array_merge([
        'user_id' => null,
        'subscriber_id' => null,
        'plan_id' => null,
        'plan_slug' => null,
        'type' => 'ai_reply',
        'operation' => 'chat',
        'channel' => 'web',
        'provider' => 'groq',
        'model' => 'llama-3.3-70b-versatile',
        'input_units' => 10,
        'output_units' => 5,
        'cached_units' => 0,
        'cost' => $cost,
        'provider_cost' => $cost,
        'communication_cost' => '0.000000',
        'external_cost' => '0.000000',
        'total_cost' => $cost,
        'currency' => 'USD',
        'cost_source' => CostSource::ModelPrice,
        'occurred_at' => CarbonImmutable::parse('2026-09-10 12:00:00', 'UTC'),
    ], $attrs));
}

function financeWindow(): array
{
    return [CarbonImmutable::parse('2026-09-01', 'UTC'), CarbonImmutable::parse('2026-10-01', 'UTC')];
}

function financeFixture(): array
{
    $alice = User::factory()->create(['is_admin' => false]);
    $bob = User::factory()->create(['is_admin' => false]);

    // Alice on plan 1 (basic): two priced rows + one unpriced (no price).
    financeRow(['user_id' => $alice->id, 'subscriber_id' => $alice->id, 'plan_id' => 1, 'plan_slug' => 'basic', 'total_cost' => '0.100000', 'provider_cost' => '0.100000', 'channel' => 'whatsapp', 'input_units' => 100, 'output_units' => 50]);
    financeRow(['user_id' => $alice->id, 'subscriber_id' => $alice->id, 'plan_id' => 1, 'plan_slug' => 'basic', 'total_cost' => '0.200000', 'provider_cost' => '0.200000', 'cost_source' => CostSource::ConfigRate, 'model' => 'gpt-4.1-mini', 'provider' => 'openai', 'operation' => 'vision']);
    financeRow(['user_id' => $alice->id, 'subscriber_id' => $alice->id, 'plan_id' => 1, 'plan_slug' => 'basic', 'total_cost' => '0.000000', 'provider_cost' => '0.000000', 'cost_source' => CostSource::None, 'input_units' => 1000, 'output_units' => 500]);

    // Bob without a plan: one priced tiny row + one currency-mismatch row.
    financeRow(['user_id' => $bob->id, 'subscriber_id' => $bob->id, 'total_cost' => '0.000001', 'provider_cost' => '0.000001']);
    financeRow(['user_id' => $bob->id, 'subscriber_id' => $bob->id, 'total_cost' => '0.000000', 'provider_cost' => '0.000000', 'cost_source' => CostSource::CurrencyMismatch]);

    // Legacy row (pre-ledger): NULL cost_source, unknown channel — a total that is NOT known cost.
    financeRow(['user_id' => $bob->id, 'subscriber_id' => $bob->id, 'total_cost' => '5.000000', 'provider_cost' => '0.000000', 'cost_source' => null, 'channel' => null, 'operation' => null, 'occurred_at' => CarbonImmutable::parse('2026-09-02 23:30:00', 'UTC')]);

    // System-attributed billable health check (real platform cost, no subscriber).
    financeRow(['total_cost' => '0.300000', 'provider_cost' => '0.300000', 'operation' => 'health_check', 'channel' => 'admin', 'occurred_at' => CarbonImmutable::parse('2026-09-30 23:59:59', 'UTC')]);

    // Outside the window — must never be counted.
    financeRow(['user_id' => $alice->id, 'subscriber_id' => $alice->id, 'plan_id' => 1, 'plan_slug' => 'basic', 'total_cost' => '9.000000', 'occurred_at' => CarbonImmutable::parse('2026-10-01 00:00:00', 'UTC')]);

    return [$alice, $bob];
}

function financeBucket(array $buckets, array $dimensions): ?CostBucket
{
    foreach ($buckets as $bucket) {
        if ($bucket->dimensions === $dimensions) {
            return $bucket;
        }
    }

    return null;
}

it('sums money from priced rows only, as exact decimal strings, and counts unpriced rows by reason', function () {
    financeFixture();
    [$from, $to] = financeWindow();
    $finance = app(FinanceQuery::class);

    $totals = $finance->totals($finance->build($from, $to));

    expect($totals->rows)->toBe(7)
        ->and($totals->pricedRows)->toBe(4)
        ->and($totals->unpricedRows)->toBe(3)
        ->and($totals->unpricedByReason)->toBe(['currency_mismatch' => 1, 'legacy' => 1, 'none' => 1])
        ->and($totals->knownTotalCost)->toBe('0.600001') // 0.1 + 0.2 + 0.000001 + 0.3 — the legacy 5.000000 is NOT known cost
        ->and($totals->knownProviderCost)->toBe('0.600001')
        ->and($totals->knownCommunicationCost)->toBe('0.000000')
        ->and($totals->knownExternalCost)->toBe('0.000000')
        ->and($totals->currency)->toBe('USD')
        ->and($totals->inputUnits)->toBe(100 + 10 + 1000 + 10 + 10 + 10 + 10)
        ->and($totals->unpricedInputUnits)->toBe(1000 + 10 + 10)
        ->and($totals->unpricedOutputUnits)->toBe(500 + 5 + 5)
        ->and($totals->systemRows)->toBe(1)
        ->and($totals->whatsappChannelRows)->toBe(1)
        ->and($totals->unknownChannelRows)->toBe(1);

    foreach ([$totals->knownTotalCost, $totals->knownProviderCost, $totals->knownCommunicationCost, $totals->knownExternalCost] as $money) {
        expect($money)->toBeString()->toMatch('/^\d+\.\d{6}$/');
    }
});

it('reports coverage: provider incomplete with unpriced rows, communication incomplete with WhatsApp/unknown-channel usage, external without a producer', function () {
    financeFixture();
    [$from, $to] = financeWindow();
    $finance = app(FinanceQuery::class);

    $coverage = $finance->coverage($finance->totals($finance->build($from, $to)));

    expect($coverage->provider)->toBe(CoverageStatus::Incomplete)
        ->and($coverage->providerUnpricedRows)->toBe(3)
        ->and($coverage->communication)->toBe(CoverageStatus::Incomplete)
        ->and($coverage->communicationUncoveredRows)->toBe(2)
        ->and($coverage->external)->toBe(CoverageStatus::NoProducer)
        ->and($coverage->knownCostIsFullServiceCost())->toBeFalse()
        ->and($coverage->warnings())->toContain('PROVIDER COST COVERAGE INCOMPLETE (3 unpriced rows)')
        ->and(implode("\n", $coverage->warnings()))->toContain('COMMUNICATION COST COVERAGE INCOMPLETE')
        ->and($coverage->warnings())->toContain('EXTERNAL COST: NO PRODUCER');
});

it('never treats a fully priced, web-only window as full service cost while external cost has no producer', function () {
    financeRow(['total_cost' => '0.100000', 'channel' => 'web']);
    [$from, $to] = financeWindow();
    $finance = app(FinanceQuery::class);

    $coverage = $finance->coverage($finance->totals($finance->build($from, $to)));

    expect($coverage->provider)->toBe(CoverageStatus::Complete)
        ->and($coverage->communication)->toBe(CoverageStatus::NoProducer)
        ->and($coverage->external)->toBe(CoverageStatus::NoProducer)
        ->and($coverage->knownCostIsFullServiceCost())->toBeFalse()
        ->and($coverage->warnings())->toBe(['COMMUNICATION COST: NO PRODUCER', 'EXTERNAL COST: NO PRODUCER']);
});

it('a window holding only unpriced rows has a known cost of zero AND unpriced rows — never "free"', function () {
    financeRow(['total_cost' => '0.000000', 'cost_source' => CostSource::None]);
    financeRow(['total_cost' => '3.000000', 'cost_source' => null]);
    [$from, $to] = financeWindow();
    $finance = app(FinanceQuery::class);

    $totals = $finance->totals($finance->build($from, $to));

    expect($totals->knownTotalCost)->toBe('0.000000')
        ->and($totals->pricedRows)->toBe(0)
        ->and($totals->unpricedRows)->toBe(2)
        ->and($totals->hasUnpriced())->toBeTrue()
        ->and($finance->coverage($totals)->provider)->toBe(CoverageStatus::Incomplete);
});

it('breaks cost down per plan with system rows and no-plan rows in their own buckets', function () {
    financeFixture();
    [$from, $to] = financeWindow();
    $finance = app(FinanceQuery::class);

    $buckets = $finance->byPlan($finance->build($from, $to));

    $basic = financeBucket($buckets, ['attribution' => 'subscriber', 'plan_id' => 1, 'plan_slug' => 'basic']);
    $none = financeBucket($buckets, ['attribution' => 'subscriber', 'plan_id' => null, 'plan_slug' => null]);
    $system = financeBucket($buckets, ['attribution' => 'system', 'plan_id' => null, 'plan_slug' => null]);

    expect($buckets)->toHaveCount(3)
        ->and($basic->rows)->toBe(3)->and($basic->pricedRows)->toBe(2)->and($basic->unpricedRows)->toBe(1)->and($basic->knownCost)->toBe('0.300000')
        ->and($none->rows)->toBe(3)->and($none->pricedRows)->toBe(1)->and($none->unpricedRows)->toBe(2)->and($none->knownCost)->toBe('0.000001')
        ->and($system->rows)->toBe(1)->and($system->knownCost)->toBe('0.300000');
});

it('breaks cost down per provider/model and per operation/channel', function () {
    financeFixture();
    [$from, $to] = financeWindow();
    $finance = app(FinanceQuery::class);
    $query = $finance->build($from, $to);

    $byModel = $finance->byProviderModel($query);
    $groq = financeBucket($byModel, ['provider' => 'groq', 'model' => 'llama-3.3-70b-versatile']);
    $openai = financeBucket($byModel, ['provider' => 'openai', 'model' => 'gpt-4.1-mini']);

    expect($byModel)->toHaveCount(2)
        ->and($groq->rows)->toBe(6)->and($groq->knownCost)->toBe('0.400001')->and($groq->unpricedRows)->toBe(3)
        ->and($openai->rows)->toBe(1)->and($openai->knownCost)->toBe('0.200000');

    $byOp = $finance->byOperationChannel($query);

    expect(financeBucket($byOp, ['operation' => 'chat', 'channel' => 'whatsapp'])->knownCost)->toBe('0.100000')
        ->and(financeBucket($byOp, ['operation' => 'vision', 'channel' => 'web'])->knownCost)->toBe('0.200000')
        ->and(financeBucket($byOp, ['operation' => 'health_check', 'channel' => 'admin'])->knownCost)->toBe('0.300000')
        ->and(financeBucket($byOp, ['operation' => null, 'channel' => null])->rows)->toBe(1)
        ->and(financeBucket($byOp, ['operation' => null, 'channel' => null])->knownCost)->toBe('0.000000');
});

it('ranks high-cost subscribers by known cost, excludes system rows and exposes only the pseudonymous id', function () {
    [$alice, $bob] = financeFixture();
    [$from, $to] = financeWindow();
    $finance = app(FinanceQuery::class);

    $top = $finance->topSubscribers($finance->build($from, $to), 10);

    expect($top)->toHaveCount(2)
        ->and($top[0]->dimensions)->toBe(['subscriber_id' => $alice->id])
        ->and($top[0]->knownCost)->toBe('0.300000')
        ->and($top[0]->unpricedRows)->toBe(1)
        ->and($top[1]->dimensions)->toBe(['subscriber_id' => $bob->id])
        ->and($top[1]->knownCost)->toBe('0.000001')
        ->and($top[1]->unpricedRows)->toBe(2)
        ->and(array_keys($top[0]->dimensions))->toBe(['subscriber_id']);

    expect($finance->topSubscribers($finance->build($from, $to), 1))->toHaveCount(1);
});

it('buckets the trend by UTC day and month (a 23:59:59 row belongs to its UTC day)', function () {
    financeFixture();
    [$from, $to] = financeWindow();
    $finance = app(FinanceQuery::class);
    $query = $finance->build($from, $to);

    $days = $finance->trend($query, 'day');

    expect(array_map(static fn (CostBucket $b) => $b->dimensions['bucket'], $days))->toBe(['2026-09-02', '2026-09-10', '2026-09-30'])
        ->and($days[0]->knownCost)->toBe('0.000000')->and($days[0]->unpricedRows)->toBe(1)
        ->and($days[1]->knownCost)->toBe('0.300001')->and($days[1]->rows)->toBe(5)
        ->and($days[2]->knownCost)->toBe('0.300000');

    $months = $finance->trend($finance->build($from, $to, [], FinanceQuery::MAX_TREND_DAYS), 'month');

    expect($months)->toHaveCount(1)
        ->and($months[0]->dimensions['bucket'])->toBe('2026-09')
        ->and($months[0]->knownCost)->toBe('0.600001')
        ->and($months[0]->unpricedRows)->toBe(3);
});

it('filters by plan (id or none), channel and attribution on top of the usage filters', function () {
    financeFixture();
    [$from, $to] = financeWindow();
    $finance = app(FinanceQuery::class);

    expect($finance->totals($finance->build($from, $to, ['plan_id' => '1']))->rows)->toBe(3)
        ->and($finance->totals($finance->build($from, $to, ['plan_id' => 'none']))->rows)->toBe(4)
        ->and($finance->totals($finance->build($from, $to, ['channel' => 'whatsapp']))->rows)->toBe(1)
        ->and($finance->totals($finance->build($from, $to, ['attribution' => 'system']))->rows)->toBe(1)
        ->and($finance->totals($finance->build($from, $to, ['attribution' => 'subscriber']))->rows)->toBe(6)
        ->and($finance->totals($finance->build($from, $to, ['provider' => 'openai', 'cost' => 'priced']))->knownTotalCost)->toBe('0.200000');
});

it('requires a bounded window: 92 days for breakdowns, 366 for the monthly trend', function () {
    $finance = app(FinanceQuery::class);
    $from = CarbonImmutable::parse('2026-01-01', 'UTC');

    expect(fn () => $finance->build($from, $from))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $finance->build($from, $from->addDays(93)))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $finance->build($from, $from->addDays(367), [], FinanceQuery::MAX_TREND_DAYS))->toThrow(InvalidArgumentException::class);

    $finance->build($from, $from->addDays(92));
    $finance->build($from, $from->addDays(366), [], FinanceQuery::MAX_TREND_DAYS);
    expect(true)->toBeTrue();
});
