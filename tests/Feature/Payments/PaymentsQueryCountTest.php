<?php

declare(strict_types=1);

use App\Services\Payments\AllocationService;
use App\Support\Rbac\Role;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Phase E5.2a performance guards: the list issues a fixed number of queries
 * per page whatever the number of rows (no N+1 on the per-row refunded /
 * allocated sums), the detail is fixed whatever the number of refunds and
 * allocations, and on PostgreSQL the filter combinations use the existing
 * indexes — no migration.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));
});

function paymentQueries(callable $fn): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $fn();
    $n = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $n;
}

it('payments list: the same number of queries with 3 rows and with 40 rows (paginated, sums keyed per page)', function () {
    $fx = closableMonth();
    $this->actingAs(userWithRole(Role::Finance));
    $url = route('dashboard.finance.payments', ['from' => '2026-08-01', 'to' => '2026-08-31']);
    $this->get($url)->assertOk(); // warm caches
    $small = paymentQueries(fn () => $this->get($url)->assertOk());

    for ($i = 0; $i < 38; $i++) {
        e1Payment($fx['subscriber'], ['amount' => '1.00', 'currency' => 'USD', 'receivedAt' => CarbonImmutable::parse('2026-08-15', 'UTC')->addMinutes($i)]);
    }
    $large = paymentQueries(fn () => $this->get($url)->assertOk()->assertSee('40 rows'));
    expect($large)->toBe($small);
});

it('payment detail and refund detail: the same number of queries with 1 refund / 1 allocation and with 20 refunds / 11 allocations', function () {
    $fx = closableMonth();
    app(AllocationService::class)->allocatePayment($fx['usd']->id, periodEvent($fx['subscriber'])->id, '1.00');
    $this->actingAs(userWithRole(Role::Finance));
    $paymentUrl = route('dashboard.finance.payments.show', $fx['usd']->id);
    $refundUrl = route('dashboard.finance.refunds.show', $fx['refund']->id);
    $this->get($paymentUrl)->assertOk();
    $this->get($refundUrl)->assertOk();
    $smallPayment = paymentQueries(fn () => $this->get($paymentUrl)->assertOk());
    $smallRefund = paymentQueries(fn () => $this->get($refundUrl)->assertOk());

    for ($i = 0; $i < 19; $i++) {
        e1Refund($fx['usd'], ['amount' => '1.00', 'refundedAt' => CarbonImmutable::parse('2026-08-15', 'UTC')->addMinutes($i)]);
    }
    for ($i = 0; $i < 10; $i++) {
        app(AllocationService::class)->allocatePayment($fx['usd']->id, periodEvent($fx['subscriber'])->id, '1.00');
    }
    $largePayment = paymentQueries(fn () => $this->get($paymentUrl)->assertOk());
    $largeRefund = paymentQueries(fn () => $this->get($refundUrl)->assertOk());
    expect($largePayment)->toBe($smallPayment)->and($largeRefund)->toBe($smallRefund);
});

it('PostgreSQL EXPLAIN: the list filters use the existing received_at / status / subscriber indexes and the refund list uses refunded_at — no new migration', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('EXPLAIN check runs on PostgreSQL only.');
    }

    $fx = closableMonth();
    for ($i = 0; $i < 300; $i++) {
        e1Payment($fx['subscriber'], ['amount' => '1.00', 'currency' => 'USD', 'receivedAt' => CarbonImmutable::parse('2026-07-01', 'UTC')->addMinutes($i)]);
    }
    for ($i = 0; $i < 200; $i++) {
        e1Refund($fx['usd'], ['amount' => '0.10', 'refundedAt' => CarbonImmutable::parse('2026-08-11', 'UTC')->addMinutes($i)]);
    }
    DB::statement('ANALYZE customer_payments');
    DB::statement('ANALYZE customer_refunds');

    $plan = fn (string $sql, array $b) => collect(DB::select('EXPLAIN '.$sql, $b))->pluck('QUERY PLAN')->implode("\n");
    expect($plan('SELECT * FROM customer_payments WHERE received_at >= ? AND received_at < ? ORDER BY id DESC LIMIT 25', ['2026-08-01 00:00:00.000000', '2026-09-01 00:00:00.000000']))->toContain('customer_payments_received_idx')
        ->and($plan('SELECT * FROM customer_payments WHERE subscriber_id = ? AND received_at >= ? AND received_at < ? ORDER BY id DESC LIMIT 25', [$fx['subscriber']->id + 1, '2026-08-01 00:00:00.000000', '2026-09-01 00:00:00.000000']))->toContain('customer_payments_subscriber_received_idx')
        ->and($plan('SELECT * FROM customer_payments WHERE current_status = ? AND received_at >= ? AND received_at < ? ORDER BY id DESC LIMIT 25', ['disputed', '2026-08-01 00:00:00.000000', '2026-09-01 00:00:00.000000']))->toMatch('/customer_payments_(status|received)_idx/')
        ->and($refundPlan = $plan('SELECT * FROM customer_refunds WHERE refunded_at >= ? AND refunded_at < ? ORDER BY id DESC LIMIT 25', ['2026-08-01 00:00:00.000000', '2026-09-01 00:00:00.000000']))->toMatch('/Index Scan.*customer_refunds_(refunded_idx|pkey)/s')
        ->and($refundPlan)->not->toContain('Seq Scan'); // id-desc + LIMIT: the pkey backward scan is the cheapest index plan when most rows match; never a sequential scan
});
