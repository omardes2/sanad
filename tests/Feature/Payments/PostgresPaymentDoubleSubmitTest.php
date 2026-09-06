<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentEvent;
use App\Models\CustomerRefund;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * GENUINE parallel double-submit tests for Phase E5.2a on PostgreSQL
 * (separate PHP processes through the same E1 services the pages call):
 *  - 6 concurrent refunds with the SAME idempotency key ⇒ exactly one refund
 *    row, one audit, the others receive the same row;
 *  - 6 concurrent disputes with the SAME rendered state token ⇒ exactly one
 *    `disputed` event and one audit, five stale; resolve likewise;
 *  - 6 concurrent claims of the same UI attempt key ⇒ exactly one wins (the
 *    duplicate-submit layer for actions the services do not key).
 * Runs only on a reachable pgsql connection; removes only its own rows.
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

it('of 6 concurrent refunds with the same idempotency key exactly one is created; the others receive the same row; one audit', function () {
    $user = User::factory()->create(['is_admin' => false]);
    $payment = e1Payment($user, ['amount' => '100.00']);
    $key = 'ui:dbl-'.str()->random(8);
    $refundedAt = CarbonImmutable::now('UTC')->subMinute()->format('Y-m-d H:i:s'); // one payload, replayed six times — what a double click sends

    try {
        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = e1Run(['refund', (string) $payment->id, $key, '30.00', $refundedAt]);
        }
        $outcomes = e1Outcomes($processes);
        $refunds = CustomerRefund::query()->where('customer_payment_id', $payment->id)->get();

        expect(array_filter($outcomes, fn ($o) => str_starts_with($o, 'ok:')))->toHaveCount(1, implode(' ', $outcomes))
            ->and(array_filter($outcomes, fn ($o) => str_starts_with($o, 'existing:')))->toHaveCount(5)
            ->and($refunds)->toHaveCount(1)->and((string) $refunds[0]->amount)->toBe('30.00')
            ->and(array_unique(array_map(fn ($o) => explode(':', $o)[1], $outcomes)))->toBe([(string) $refunds[0]->id])
            ->and(AuditLog::where('action', 'payment.refunded')->where('subject_id', $payment->id)->count())->toBe(1);
    } finally {
        e1Cleanup($user);
    }
});

it('of 6 concurrent disputes with the same rendered token exactly one appends the disputed event; five are stale and write nothing; resolve behaves the same', function () {
    $user = User::factory()->create(['is_admin' => false]);
    $payment = e1Payment($user, ['amount' => '100.00']);
    $token = $payment->stateToken();

    try {
        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = e1Run(['dispute', (string) $payment->id, $token]);
        }
        $outcomes = e1Outcomes($processes);

        expect(array_filter($outcomes, fn ($o) => str_starts_with($o, 'ok:')))->toHaveCount(1)
            ->and(array_filter($outcomes, fn ($o) => $o === 'stale'))->toHaveCount(5)
            ->and(CustomerPaymentEvent::query()->where('customer_payment_id', $payment->id)->where('event_type', 'disputed')->count())->toBe(1)
            ->and(CustomerPaymentEvent::query()->where('customer_payment_id', $payment->id)->where('event_type', 'succeeded')->count())->toBe(1)
            ->and($payment->fresh()->current_status->value)->toBe('disputed')
            ->and(AuditLog::where('action', 'payment.transitioned')->where('subject_id', $payment->id)->count())->toBe(1);

        $token = $payment->fresh()->stateToken();
        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = e1Run(['resolve', (string) $payment->id, $token]);
        }
        $outcomes = e1Outcomes($processes);
        expect(array_filter($outcomes, fn ($o) => str_starts_with($o, 'ok:')))->toHaveCount(1)->and(array_filter($outcomes, fn ($o) => $o === 'stale'))->toHaveCount(5)
            ->and(CustomerPaymentEvent::query()->where('customer_payment_id', $payment->id)->where('event_type', 'dispute_resolved')->count())->toBe(1)
            ->and(CustomerPayment::query()->where('subscriber_id', $user->id)->count())->toBe(1) // resolve creates no payment
            ->and(AuditLog::where('action', 'payment.transitioned')->where('subject_id', $payment->id)->count())->toBe(2);
    } finally {
        e1Cleanup($user);
    }
});

it('of 6 concurrent claims of the same UI attempt key exactly one wins — the duplicate-submit layer for allocations', function () {
    $nonce = 'ui:claim-'.str()->random(8);
    $processes = [];
    for ($i = 0; $i < 6; $i++) {
        // The processes must share one cache store (phpunit exports CACHE_STORE=array to children, which would give each its own): the database store is shared and atomic.
        $p = new Process(['php', 'artisan', 'sanad:payment-probe', 'claim', $nonce], base_path(), ['CACHE_STORE' => 'database']);
        $p->start();
        $processes[] = $p;
    }
    $outcomes = e1Outcomes($processes);

    expect(array_filter($outcomes, fn ($o) => $o === 'ok:claimed'))->toHaveCount(1)->and(array_filter($outcomes, fn ($o) => $o === 'duplicate'))->toHaveCount(5);
});
