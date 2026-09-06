<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentEvent;
use App\Models\CustomerRefund;
use App\Models\PaymentAllocation;
use App\Models\Plan;
use App\Models\RefundAllocation;
use App\Models\User;
use App\Services\Payments\AllocationService;
use Illuminate\Support\Facades\DB;

/**
 * GENUINE parallel tests for Phase E1 on PostgreSQL (separate PHP processes,
 * real row locks and unique indexes, 25P02-safe savepoints):
 *  - idempotency race: N recorders of the SAME idempotency key ⇒ exactly one
 *    payment identity / event set / audit row; the others get the same row;
 *    different facts ⇒ conflict;
 *  - refund race: concurrent refunds whose sum exceeds the payment ⇒ every
 *    one fully succeeds or is fully refused, Σ successful ≤ the payment,
 *    never clipped;
 *  - allocation race: the same rule against the payment amount;
 *  - refund-allocation race: ≤ the refund AND ≤ the allocation.
 *
 * Runs only on a reachable pgsql connection. Not wrapped in RefreshDatabase;
 * it removes only the rows it created (query builder — the models refuse
 * deletes by design).
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

it('of 6 concurrent recordings of the same idempotency key exactly one creates the payment; the others receive the same row; different facts conflict', function () {
    $user = User::factory()->create(['is_admin' => false]);
    $key = 'race-'.str()->random(8);
    $receivedAt = '2026-09-01 10:00:00';

    try {
        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = e1Run(['record', (string) $user->id, $key, '100.00', 'USD', $receivedAt]);
        }
        $outcomes = e1Outcomes($processes);

        $created = array_values(array_filter($outcomes, fn ($o) => str_starts_with($o, 'created:')));
        $existing = array_values(array_filter($outcomes, fn ($o) => str_starts_with($o, 'existing:')));
        $payments = CustomerPayment::query()->where('subscriber_id', $user->id)->get();

        expect($created)->toHaveCount(1)
            ->and($existing)->toHaveCount(5)
            ->and($payments)->toHaveCount(1) // one identity
            ->and(array_unique(array_map(fn ($o) => explode(':', $o)[1], $outcomes)))->toBe([(string) $payments[0]->id]) // everyone got the same row
            ->and(CustomerPaymentEvent::query()->where('customer_payment_id', $payments[0]->id)->count())->toBe(2) // one created + one succeeded
            ->and($payments[0]->current_status->value)->toBe('succeeded')
            ->and(AuditLog::where('subject_type', (new CustomerPayment)->getMorphClass())->where('subject_id', $payments[0]->id)->count())->toBe(1);

        // Same key, different facts (amount) ⇒ conflict, nothing written.
        $conflict = e1Run(['record', (string) $user->id, $key, '100.01', 'USD', $receivedAt]);
        $conflict->wait();
        expect(trim($conflict->getOutput()))->toBe('conflict')
            ->and(CustomerPayment::query()->where('subscriber_id', $user->id)->count())->toBe(1)
            ->and(CustomerPaymentEvent::query()->where('customer_payment_id', $payments[0]->id)->count())->toBe(2);
    } finally {
        e1Cleanup($user);
    }
});

it('of 6 concurrent refunds of 30.00 against a 100.00 payment exactly three succeed in full and three are refused in full: Σ = 90.00 ≤ 100.00, never clipped', function () {
    $user = User::factory()->create(['is_admin' => false]);
    $payment = e1Payment($user, ['amount' => '100.00']);

    try {
        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = e1Run(['refund', (string) $payment->id, 'rf-'.$i.'-'.str()->random(4), '30.00']);
        }
        $outcomes = e1Outcomes($processes);
        $counts = array_count_values(array_map(fn ($o) => explode(':', $o)[0], $outcomes));
        $refunds = CustomerRefund::query()->where('customer_payment_id', $payment->id)->get();

        expect($counts['ok'] ?? 0)->toBe(3)
            ->and($counts['rejected'] ?? 0)->toBe(3)
            ->and(array_filter($outcomes, fn ($o) => $o === 'rejected:refund_limit'))->toHaveCount(3)
            ->and($refunds)->toHaveCount(3)
            ->and($refunds->pluck('amount')->map(fn ($a) => (string) $a)->unique()->all())->toBe(['30.00']) // no clipping
            ->and((string) $refunds->sum(fn ($r) => (int) round((float) $r->amount * 100)))->toBe('9000') // 90.00 ≤ 100.00
            ->and(AuditLog::where('action', 'payment.refunded')->where('subject_id', $payment->id)->count())->toBe(3)
            ->and((string) $payment->fresh()->amount)->toBe('100.00');
    } finally {
        e1Cleanup($user);
    }
});

it('of 6 concurrent allocations of 30.00 from a 100.00 payment exactly three succeed and three are refused: Σ = 90.00 ≤ 100.00, never clipped', function () {
    $user = User::factory()->create(['is_admin' => false]);
    $plan = Plan::create(['name' => 'PG race', 'slug' => 'pg-e1-'.str()->random(5), 'price' => '10.00', 'currency' => 'USD', 'billing_period' => 'monthly', 'trial_days' => 0, 'limits' => [], 'features' => [], 'is_active' => true, 'is_default' => false, 'sort_order' => 99]);
    $event = e1PeriodEvent($user, $plan);
    $payment = e1Payment($user, ['amount' => '100.00']);

    try {
        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = e1Run(['allocate', (string) $payment->id, (string) $event->id, '30.00']);
        }
        $outcomes = e1Outcomes($processes);
        $counts = array_count_values(array_map(fn ($o) => explode(':', $o)[0], $outcomes));
        $allocations = PaymentAllocation::query()->where('customer_payment_id', $payment->id)->get();

        expect($counts['ok'] ?? 0)->toBe(3)
            ->and(array_filter($outcomes, fn ($o) => $o === 'rejected:allocation_limit'))->toHaveCount(3)
            ->and($allocations)->toHaveCount(3)
            ->and($allocations->pluck('amount')->map(fn ($a) => (string) $a)->unique()->all())->toBe(['30.00'])
            ->and($allocations->pluck('subscription_event_id')->unique()->all())->toBe([$event->id])
            ->and($allocations->first()->period_start->toDateString())->toBe('2026-09-01')
            ->and(AuditLog::where('action', 'payment.allocated')->where('subject_id', $payment->id)->count())->toBe(3);
    } finally {
        e1Cleanup($user, $plan);
    }
});

it('concurrent refund allocations never exceed the refund nor the allocation they reverse', function () {
    $user = User::factory()->create(['is_admin' => false]);
    $plan = Plan::create(['name' => 'PG race', 'slug' => 'pg-e1-'.str()->random(5), 'price' => '10.00', 'currency' => 'USD', 'billing_period' => 'monthly', 'trial_days' => 0, 'limits' => [], 'features' => [], 'is_active' => true, 'is_default' => false, 'sort_order' => 99]);
    $event = e1PeriodEvent($user, $plan);
    $payment = e1Payment($user, ['amount' => '100.00']);
    $service = app(AllocationService::class);
    $big = $service->allocatePayment($payment->id, $event->id, '70.00');
    $small = $service->allocatePayment($payment->id, $event->id, '30.00');
    $refund = e1Refund($payment, ['amount' => '50.00']);

    try {
        // (a) Bound by the REFUND: 6 × 20.00 against a 50.00 refund on a 70.00 allocation ⇒ exactly 2 succeed (40.00).
        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = e1Run(['allocate-refund', (string) $refund->id, (string) $big->id, '20.00']);
        }
        $outcomes = e1Outcomes($processes);
        $counts = array_count_values(array_map(fn ($o) => explode(':', $o)[0], $outcomes));

        expect($counts['ok'] ?? 0)->toBe(2)
            ->and(array_filter($outcomes, fn ($o) => $o === 'rejected:refund_allocation_limit'))->toHaveCount(4)
            ->and(RefundAllocation::query()->where('customer_refund_id', $refund->id)->count())->toBe(2)
            ->and(RefundAllocation::query()->where('customer_refund_id', $refund->id)->pluck('amount')->map(fn ($a) => (string) $a)->unique()->all())->toBe(['20.00']);

        // (b) Bound by the ALLOCATION: 10.00 of the refund is left; 6 × 10.00 against the 30.00 allocation ⇒ exactly 1 succeeds
        //     (the refund is exhausted), and against a fresh 100.00 refund on the 30.00 allocation 6 × 20.00 ⇒ exactly 1 (allocation bound).
        $refund2 = e1Refund($payment, ['amount' => '50.00']);
        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = e1Run(['allocate-refund', (string) $refund2->id, (string) $small->id, '20.00']);
        }
        $outcomes = e1Outcomes($processes);
        $counts = array_count_values(array_map(fn ($o) => explode(':', $o)[0], $outcomes));

        expect($counts['ok'] ?? 0)->toBe(1) // 20.00 fits; a second 20.00 would make 40.00 > 30.00 allocation
            ->and(array_filter($outcomes, fn ($o) => $o === 'rejected:allocation_reversal_limit'))->toHaveCount(5)
            ->and(RefundAllocation::query()->where('payment_allocation_id', $small->id)->sum('amount'))->toEqual(20)
            ->and((string) $small->fresh()->amount)->toBe('30.00') // the original allocation is never modified
            ->and((string) $big->fresh()->amount)->toBe('70.00')
            ->and(AuditLog::where('action', 'refund.allocated')->where('subject_id', $payment->id)->count())->toBe(3);
    } finally {
        e1Cleanup($user, $plan);
    }
});
