<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\PaymentAllocation;
use App\Models\RefundAllocation;
use App\Models\User;
use App\Services\Payments\AllocationService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * GENUINE parallel allocation double-submit proof for Phase E5.2a on
 * PostgreSQL (separate PHP processes), in two layers, stated honestly:
 *
 *  A. the E1 services alone (AllocationService::allocatePayment /
 *     allocateRefund take NO idempotency key): 6 concurrent 20.00
 *     allocations of a 100.00 payment with the same UI attempt key ⇒ the
 *     cap admits FIVE rows (Σ 100.00) — the cap is a bound, not a dedupe;
 *  B. the UI attempt-key claim (SubmitAttempt over a SHARED cache store):
 *     6 concurrent ⇒ exactly one row and one audit; a same-key resubmit
 *     (same or different payload) is refused as a duplicate — but the guard
 *     cannot return "the same result", cannot tell a conflict from a
 *     replay, and once the claim is gone (TTL / cache flush / restart) a
 *     second row IS created: not durable.
 *
 * Durable allocation idempotency therefore needs a service-level key with a
 * unique column (a migration) — proposed, not applied here.
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

/** Probe processes share the DATABASE cache store (phpunit exports CACHE_STORE=array to children, which would isolate each process). */
function claimedRun(array $args): Process
{
    $p = new Process(['php', 'artisan', 'sanad:payment-probe', ...$args], base_path(), ['CACHE_STORE' => 'database']);
    $p->start();

    return $p;
}

it('A. services alone: 6 concurrent 20.00 allocations with the same attempt key ⇒ five rows (Σ 100.00) and five audits — the cap bounds, it does not dedupe', function () {
    $user = User::factory()->create(['is_admin' => false]);
    $plan = billingPlan();
    $payment = e1Payment($user, ['amount' => '100.00']);
    $event = e1PeriodEvent($user, $plan);

    try {
        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = e1Run(['allocate', (string) $payment->id, (string) $event->id, '20.00']); // same payload, no key the service could honour
        }
        $outcomes = e1Outcomes($processes);
        $rows = PaymentAllocation::query()->where('customer_payment_id', $payment->id)->get();

        expect(array_filter($outcomes, fn ($o) => str_starts_with($o, 'ok:')))->toHaveCount(5)
            ->and(array_filter($outcomes, fn ($o) => $o === 'rejected:allocation_limit'))->toHaveCount(1)
            ->and($rows)->toHaveCount(5)->and($rows->sum(fn ($r) => (int) round((float) $r->amount * 100)))->toBe(10000)
            ->and(AuditLog::where('action', 'payment.allocated')->where('subject_id', $payment->id)->count())->toBe(5);
    } finally {
        e1Cleanup($user, $plan);
    }
});

it('B. UI claim layer: 6 concurrent 20.00 allocations with the same attempt key ⇒ one row and one audit; same key + same payload and same key + 25.00 are both refused as duplicates; once the claim is gone a second row is created (not durable)', function () {
    $user = User::factory()->create(['is_admin' => false]);
    $plan = billingPlan();
    $payment = e1Payment($user, ['amount' => '100.00']);
    $event = e1PeriodEvent($user, $plan);
    $key = 'ui:alloc-'.str()->random(8);

    try {
        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = claimedRun(['allocate-claimed', $key, (string) $payment->id, (string) $event->id, '20.00']);
        }
        $outcomes = e1Outcomes($processes);
        $audits = fn () => AuditLog::where('action', 'payment.allocated')->where('subject_id', $payment->id)->count();

        expect(array_filter($outcomes, fn ($o) => str_starts_with($o, 'ok:')))->toHaveCount(1, implode(' ', $outcomes))
            ->and(array_filter($outcomes, fn ($o) => $o === 'duplicate'))->toHaveCount(5)
            ->and(PaymentAllocation::query()->where('customer_payment_id', $payment->id)->count())->toBe(1)
            ->and((string) PaymentAllocation::query()->where('customer_payment_id', $payment->id)->first()->amount)->toBe('20.00')
            ->and($audits())->toBe(1);

        // same key + same payload ⇒ refused as duplicate (no extra row) — but NOT "the same result": the guard does not know the row
        $same = claimedRun(['allocate-claimed', $key, (string) $payment->id, (string) $event->id, '20.00']);
        $same->wait();
        // same key + different payload (25.00) ⇒ refused as duplicate too — NOT a conflict: the guard carries no payload fingerprint
        $diff = claimedRun(['allocate-claimed', $key, (string) $payment->id, (string) $event->id, '25.00']);
        $diff->wait();
        expect(trim($same->getOutput()))->toBe('duplicate')->and(trim($diff->getOutput()))->toBe('duplicate')
            ->and(PaymentAllocation::query()->where('customer_payment_id', $payment->id)->count())->toBe(1)->and($audits())->toBe(1);

        // the claim is not durable: once it is gone (TTL expiry, cache flush, restart) the same key creates a second row
        $release = claimedRun(['release', 'allocation', $key]);
        $release->wait();
        $again = claimedRun(['allocate-claimed', $key, (string) $payment->id, (string) $event->id, '20.00']);
        $again->wait();
        expect(trim($again->getOutput()))->toStartWith('ok:')
            ->and(PaymentAllocation::query()->where('customer_payment_id', $payment->id)->count())->toBe(2)->and($audits())->toBe(2);
    } finally {
        e1Cleanup($user, $plan);
    }
});

it('refund allocation, both layers: services alone admit up to the caps; the claim layer yields one row per attempt key but is not durable', function () {
    $user = User::factory()->create(['is_admin' => false]);
    $plan = billingPlan();
    $payment = e1Payment($user, ['amount' => '100.00']);
    $event = e1PeriodEvent($user, $plan);
    $allocation = app(AllocationService::class)->allocatePayment($payment->id, $event->id, '100.00');
    $refund = e1Refund($payment, ['amount' => '60.00']);
    $key = 'ui:ralloc-'.str()->random(8);

    try {
        // A. services alone: 6 × 20.00 against a 60.00 refund ⇒ three rows (Σ 60.00), three refused by the refund cap
        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = e1Run(['allocate-refund', (string) $refund->id, (string) $allocation->id, '20.00']);
        }
        $outcomes = e1Outcomes($processes);
        expect(array_filter($outcomes, fn ($o) => str_starts_with($o, 'ok:')))->toHaveCount(3)
            ->and(array_filter($outcomes, fn ($o) => $o === 'rejected:refund_allocation_limit'))->toHaveCount(3)
            ->and(RefundAllocation::query()->where('customer_refund_id', $refund->id)->count())->toBe(3);

        // B. claim layer on a fresh refund: 6 concurrent ⇒ one row; same key again (same or different amount) ⇒ duplicate; after release ⇒ a second row
        $refund2 = e1Refund($payment, ['amount' => '40.00']);
        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = claimedRun(['allocate-refund-claimed', $key, (string) $refund2->id, (string) $allocation->id, '10.00']);
        }
        $outcomes = e1Outcomes($processes);
        expect(array_filter($outcomes, fn ($o) => str_starts_with($o, 'ok:')))->toHaveCount(1, implode(' ', $outcomes))
            ->and(RefundAllocation::query()->where('customer_refund_id', $refund2->id)->count())->toBe(1)
            ->and(AuditLog::where('action', 'refund.allocated')->where('subject_id', $payment->id)->count())->toBe(4);
        foreach (['10.00', '15.00'] as $amount) {
            $p = claimedRun(['allocate-refund-claimed', $key, (string) $refund2->id, (string) $allocation->id, $amount]);
            $p->wait();
            expect(trim($p->getOutput()))->toBe('duplicate');
        }
        expect(RefundAllocation::query()->where('customer_refund_id', $refund2->id)->count())->toBe(1);
        $release = claimedRun(['release', 'refund_allocation', $key]);
        $release->wait();
        $again = claimedRun(['allocate-refund-claimed', $key, (string) $refund2->id, (string) $allocation->id, '10.00']);
        $again->wait();
        expect(trim($again->getOutput()))->toStartWith('ok:')->and(RefundAllocation::query()->where('customer_refund_id', $refund2->id)->count())->toBe(2);
    } finally {
        e1Cleanup($user, $plan);
    }
});
