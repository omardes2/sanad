<?php

declare(strict_types=1);

use App\Enums\BillingPeriod;
use App\Enums\CostSource;
use App\Models\UsageEvent;
use App\Services\Finance\FinanceQuery;
use App\Services\Finance\FinanceSql;
use App\Services\Finance\RevenueNormalizer;
use App\Support\Billing\DecimalMath;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Phase D1 — no floats in finance. Money is summed as scaled integers INSIDE
 * the database and formatted by DecimalMath, so the same fixture yields the
 * same exact strings on SQLite and PostgreSQL (this file runs on both in CI).
 */
it('sums ten rows of 0.100000 to exactly 1.000000 (a float sum would give 0.9999999999999999)', function () {
    for ($i = 0; $i < 10; $i++) {
        UsageEvent::factory()->create(['user_id' => null, 'subscriber_id' => null, 'cost_source' => CostSource::ModelPrice, 'total_cost' => '0.100000', 'provider_cost' => '0.100000', 'occurred_at' => CarbonImmutable::parse('2026-09-10 12:00:00', 'UTC')]);
    }

    $finance = app(FinanceQuery::class);
    $totals = $finance->totals($finance->build(CarbonImmutable::parse('2026-09-01', 'UTC'), CarbonImmutable::parse('2026-10-01', 'UTC')));

    expect($totals->knownTotalCost)->toBe('1.000000')
        ->and($totals->knownProviderCost)->toBe('1.000000');
});

it('keeps the sixth decimal exact and handles large amounts without precision loss', function () {
    $amounts = ['0.000001', '0.000001', '0.000001', '123456.654321', '0.333333', '0.666667'];

    foreach ($amounts as $amount) {
        UsageEvent::factory()->create(['user_id' => null, 'subscriber_id' => null, 'cost_source' => CostSource::ConfigRate, 'total_cost' => $amount, 'provider_cost' => $amount, 'occurred_at' => CarbonImmutable::parse('2026-09-10 12:00:00', 'UTC')]);
    }

    $finance = app(FinanceQuery::class);
    $totals = $finance->totals($finance->build(CarbonImmutable::parse('2026-09-01', 'UTC'), CarbonImmutable::parse('2026-10-01', 'UTC')));

    expect($totals->knownTotalCost)->toBe('123457.654324');
});

it('the scaled-sum SQL fragment is driver-specific and refuses unsupported drivers', function () {
    $sql = app(FinanceSql::class);
    $driver = DB::connection()->getDriverName();

    expect($sql->driver())->toBe($driver)
        ->and($sql->scaledSum('total_cost', '1=1'))->toContain($driver === 'pgsql' ? '::bigint' : 'CAST(ROUND(')
        ->and($sql->dateBucket('occurred_at', 'day'))->toContain($driver === 'pgsql' ? 'to_char' : 'strftime')
        ->and(fn () => $sql->dateBucket('occurred_at', 'week'))->toThrow(RuntimeException::class);
});

it('parses database integers without floats and rejects anything else', function () {
    expect(DecimalMath::intFromDb('123456789012345678'))->toBe(123456789012345678)
        ->and(DecimalMath::intFromDb(42))->toBe(42)
        ->and(DecimalMath::intFromDb(null))->toBe(0)
        ->and(fn () => DecimalMath::intFromDb('1.5'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => DecimalMath::intFromDb('1e3'))->toThrow(InvalidArgumentException::class);
});

it('mulDiv rounds half up in integers and detects overflow', function () {
    expect(DecimalMath::mulDiv(100, 1, 12))->toBe(8) // 8.33 → 8
        ->and(DecimalMath::mulDiv(100, 1, 8))->toBe(13) // 12.5 → 13
        ->and(DecimalMath::mulDiv(7, 52, 12))->toBe(30) // 30.33 → 30
        ->and(fn () => DecimalMath::mulDiv(PHP_INT_MAX, 2, 1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => DecimalMath::mulDiv(1, 1, 0))->toThrow(InvalidArgumentException::class);
});

it('normalises every billing period to an exact monthly figure', function (string $price, BillingPeriod $period, string $monthly) {
    expect(RevenueNormalizer::monthly($price, $period))->toBe($monthly);
})->with([
    'monthly' => ['10.00', BillingPeriod::Monthly, '10.000000'],
    'yearly' => ['100.00', BillingPeriod::Yearly, '8.333333'],
    'weekly' => ['10.00', BillingPeriod::Weekly, '43.333333'],
    'daily' => ['1.00', BillingPeriod::Daily, '30.416667'],
    'none' => ['99.99', BillingPeriod::None, '0.000000'],
    'yearly rounds half up' => ['0.01', BillingPeriod::Yearly, '0.000833'],
    'large yearly' => ['99999999.99', BillingPeriod::Yearly, '8333333.332500'],
]);

it('multiplies a monthly figure by a subscription count exactly', function () {
    expect(RevenueNormalizer::times('8.333333', 3))->toBe('24.999999')
        ->and(RevenueNormalizer::times('10.000000', 0))->toBe('0.000000');
});
