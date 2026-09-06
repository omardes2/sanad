<?php

declare(strict_types=1);

use App\Enums\CustomerPaymentEventType;
use App\Enums\PaymentSource;
use App\Models\CustomerPayment;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Services\Payments\AllocationService;
use App\Services\Payments\CashCollectedQuery;
use App\Services\Payments\CustomerPaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Phase E1 — Cash Collected semantics (event-based, per currency, UTC):
 * gross by received_at of payments with a `succeeded` event; refunds by
 * refunded_at; a later dispute never erases collected cash; NULL fees ⇒
 * FEES UNKNOWN (net-after-fees NULL, never zero); attribution figures kept
 * apart from cash; unallocated computed per payment. The same file runs on
 * SQLite and PostgreSQL — the numbers must be identical (scaled-int sums).
 */
function cashFixture(): array
{
    $usd = billingSubscriber();
    $eur = billingSubscriber();
    $august = fn (string $d) => CarbonImmutable::parse($d, 'UTC');

    $a = e1Payment($usd, ['idempotencyKey' => 'A', 'amount' => '100.00', 'receivedAt' => $august('2026-08-10 09:00:00'), 'gatewayFeeAmount' => '3.00', 'feeCurrency' => 'USD']);
    $b = e1Payment($usd, ['idempotencyKey' => 'B', 'amount' => '50.00', 'receivedAt' => $august('2026-08-20 09:00:00')]); // fee UNKNOWN
    $c = e1Payment($usd, ['idempotencyKey' => 'C', 'amount' => '80.00', 'receivedAt' => $august('2026-07-31 23:59:59.999999')]); // outside window
    $d = e1Payment($eur, ['idempotencyKey' => 'D', 'amount' => '200.00', 'currency' => 'EUR', 'receivedAt' => $august('2026-08-15 09:00:00')]);

    // A gateway-created payment that never succeeded: identity only, no cash.
    $pending = CustomerPayment::query()->create(['subscriber_id' => $usd->id, 'gateway' => 'manual', 'idempotency_key' => 'E', 'amount' => '999.00', 'currency' => 'USD', 'received_at' => $august('2026-08-12 09:00:00'), 'current_status' => 'created', 'recorded_by_ref' => 'console']);
    $pending->events()->create(['event_type' => 'created', 'occurred_at' => now(), 'source' => 'gateway', 'actor_ref' => 'system', 'created_at' => now()]);

    $r1 = e1Refund($a, ['idempotencyKey' => 'RA1', 'amount' => '30.00', 'refundedAt' => $august('2026-08-25 10:00:00')]);
    e1Refund($c, ['idempotencyKey' => 'RC1', 'amount' => '10.00', 'refundedAt' => $august('2026-08-02 10:00:00')]); // payment outside, refund inside
    e1Refund($a, ['idempotencyKey' => 'RA2', 'amount' => '5.00', 'refundedAt' => $august('2026-09-02 10:00:00')]); // refund outside

    $subscription = Subscription::create(['subscriber_id' => $usd->id, 'plan_id' => billingPlan()->id, 'status' => 'active', 'started_at' => now()]);
    $event = fn (string $from, string $to) => SubscriptionEvent::query()->create(['subscription_id' => $subscription->id, 'subscriber_id' => $usd->id, 'event_type' => 'extended', 'from_status' => 'active', 'to_status' => 'active', 'to_period_start' => $august($from), 'to_period_end' => $august($to), 'effective_at' => now(), 'source' => 'admin', 'actor_ref' => 'console']);
    $allocations = app(AllocationService::class);
    $alloc1 = $allocations->allocatePayment($a->id, $event('2026-08-01', '2026-09-01')->id, '60.00');
    $allocations->allocatePayment($a->id, $event('2026-09-01', '2026-10-01')->id, '20.00'); // period starts outside the window
    $allocations->allocateRefund($r1->id, $alloc1->id, '30.00');

    return [$a, $b, $c, $d];
}

function cashWindow(): array
{
    return app(CashCollectedQuery::class)->summarise(CarbonImmutable::parse('2026-08-01', 'UTC'), CarbonImmutable::parse('2026-09-01', 'UTC'));
}

it('sums cash by received_at of succeeded payments, refunds by refunded_at, keeps currencies apart, marks unknown fees and separates attribution from cash', function () {
    cashFixture();

    $out = cashWindow();

    expect(array_keys($out))->toBe(['EUR', 'USD']);

    $usd = $out['USD'];
    expect($usd->paymentsCount)->toBe(2) // A + B; C outside; E never succeeded
        ->and($usd->grossCashCollected)->toBe('150.00')
        ->and($usd->refundsCount)->toBe(2) // RA1 + RC1 (by refunded_at); RA2 outside
        ->and($usd->refunds)->toBe('40.00')
        ->and($usd->netCash)->toBe('110.00')
        ->and($usd->gatewayFeesKnown)->toBe('3.00')
        ->and($usd->feesUnknownCount)->toBe(1)
        ->and($usd->netCashAfterGatewayFees)->toBeNull() // FEES UNKNOWN, never 147.00
        ->and($usd->feesStatus())->toBe('FEES UNKNOWN')
        ->and($usd->allocatedCollectedAmount)->toBe('60.00') // period_start in window only
        ->and($usd->refundAllocatedAmount)->toBe('30.00')
        ->and($usd->netAllocatedAmount)->toBe('30.00')
        ->and($usd->unallocatedGrossCollectedAmount)->toBe('70.00'); // (100 − 80) + (50 − 0): refunds never erase allocations

    $eur = $out['EUR'];
    expect($eur->paymentsCount)->toBe(1)->and($eur->grossCashCollected)->toBe('200.00')->and($eur->refunds)->toBe('0.00')
        ->and($eur->netCash)->toBe('200.00')->and($eur->feesUnknownCount)->toBe(1)->and($eur->netCashAfterGatewayFees)->toBeNull()
        ->and($eur->allocatedCollectedAmount)->toBe('0.00')->and($eur->unallocatedGrossCollectedAmount)->toBe('200.00');
});

it('never erases historical cash: a later dispute leaves Gross Cash Collected unchanged', function () {
    [$a] = cashFixture();
    $before = cashWindow()['USD'];

    app(CustomerPaymentService::class)->transition($a, CustomerPaymentEventType::Disputed, $a->stateToken(), PaymentSource::Gateway, 'chargeback');

    $after = cashWindow()['USD'];
    expect($a->fresh()->current_status)->toBe(CustomerPaymentEventType::Disputed)
        ->and($after->grossCashCollected)->toBe($before->grossCashCollected)
        ->and($after->paymentsCount)->toBe($before->paymentsCount)
        ->and($after->netCash)->toBe($before->netCash);
});

it('computes Net Cash After Gateway Fees only when every fee in the window is known', function () {
    $s = billingSubscriber();
    $at = CarbonImmutable::parse('2026-08-10', 'UTC');
    e1Payment($s, ['amount' => '10.00', 'receivedAt' => $at, 'gatewayFeeAmount' => '0.30', 'feeCurrency' => 'USD']);
    e1Payment($s, ['amount' => '0.10', 'receivedAt' => $at, 'gatewayFeeAmount' => '0.00', 'feeCurrency' => 'USD']);
    e1Payment($s, ['amount' => '0.20', 'receivedAt' => $at, 'gatewayFeeAmount' => '0.01', 'feeCurrency' => 'USD']);

    $usd = cashWindow()['USD'];
    expect($usd->grossCashCollected)->toBe('10.30') // exact cents: no 10.299999
        ->and($usd->gatewayFeesKnown)->toBe('0.31')
        ->and($usd->feesUnknownCount)->toBe(0)
        ->and($usd->feesStatus())->toBe('known')
        ->and($usd->netCashAfterGatewayFees)->toBe('9.99');
});

it('returns nothing for an empty window and refuses unbounded or inverted windows', function () {
    expect(cashWindow())->toBe([]);

    $q = app(CashCollectedQuery::class);
    expect(fn () => $q->summarise(CarbonImmutable::parse('2026-09-01', 'UTC'), CarbonImmutable::parse('2026-09-01', 'UTC')))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $q->summarise(CarbonImmutable::parse('2025-01-01', 'UTC'), CarbonImmutable::parse('2026-01-03', 'UTC')))->toThrow(InvalidArgumentException::class);
});

it('reads the same figures on this engine as the specification expects (parity check on the driver in use)', function () {
    cashFixture();
    $driver = DB::connection()->getDriverName();

    expect(in_array($driver, ['sqlite', 'pgsql'], true))->toBeTrue()
        ->and(cashWindow()['USD']->grossCashCollected)->toBe('150.00')
        ->and(cashWindow()['USD']->unallocatedGrossCollectedAmount)->toBe('70.00');
});
