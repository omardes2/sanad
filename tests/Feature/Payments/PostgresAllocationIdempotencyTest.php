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
 * GENUINE parallel allocation idempotency proof for Phase E5.2a on PostgreSQL
 * (separate PHP processes, separate connections). The E1 services now REQUIRE
 * an idempotency key per new allocation and the database unique index is the
 * authority: 6 concurrent same-key writes ⇒ exactly one row and one audit;
 * a same-key replay returns the same row; a same-key different payload is a
 * conflict; a mixed 20/25 race ends with ONE canonical row.
 *
 * Layer A runs the services with NO SubmitAttempt claim at all (each probe
 * process even has its own isolated `array` cache: the cache cannot help);
 * layer B runs the UI path (claim, then the keyed service) and shows the claim
 * is convenience only — releasing it changes nothing financially.
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

/** Probe processes sharing the DATABASE cache store (phpunit exports CACHE_STORE=array to children, which isolates each process). */
function claimedRun(array $args): Process
{
    $p = new Process(['php', 'artisan', 'sanad:payment-probe', ...$args], base_path(), ['CACHE_STORE' => 'database']);
    $p->start();

    return $p;
}

/** @return array{ok: list<string>, existing: list<string>, conflict: int, rejected: list<string>, duplicate: int} */
function allocOutcomes(array $outcomes): array
{
    $ids = fn (string $prefix) => array_values(array_map(fn ($o) => substr($o, strlen($prefix)), array_filter($outcomes, fn ($o) => str_starts_with($o, $prefix))));

    return [
        'ok' => $ids('ok:'),
        'existing' => $ids('existing:'),
        'conflict' => count(array_filter($outcomes, fn ($o) => $o === 'conflict')),
        'rejected' => array_values(array_filter($outcomes, fn ($o) => str_starts_with($o, 'rejected:'))),
        'duplicate' => count(array_filter($outcomes, fn ($o) => $o === 'duplicate')),
    ];
}

it('A. service level, no cache claim: 6 concurrent 20.00 allocations of a 100.00 payment under ONE key ⇒ exactly one row (20.00), one audit, every response names that same allocation id; replay ⇒ same row, no audit; 25.00 under the same key ⇒ conflict, still one row', function () {
    $user = User::factory()->create(['is_admin' => false]);
    $plan = billingPlan();
    $payment = e1Payment($user, ['amount' => '100.00']);
    $event = e1PeriodEvent($user, $plan);
    $key = 'ui:alloc-'.str()->random(8);
    $rows = fn () => PaymentAllocation::query()->where('customer_payment_id', $payment->id)->get();
    $audits = fn () => AuditLog::where('action', 'payment.allocated')->where('subject_id', $payment->id)->count();

    try {
        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = e1Run(['allocate', (string) $payment->id, (string) $event->id, '20.00', $key]);
        }
        $o = allocOutcomes(e1Outcomes($processes));
        $row = $rows()->sole();

        expect($o['ok'])->toHaveCount(1, json_encode($o))
            ->and($o['existing'])->toHaveCount(5)
            ->and($o['conflict'])->toBe(0)->and($o['rejected'])->toBe([])
            ->and(array_unique([...$o['ok'], ...$o['existing']]))->toBe([(string) $row->id]) // every process points at the same allocation
            ->and((string) $row->amount)->toBe('20.00')->and($row->idempotency_key)->toBe($key)
            ->and($rows()->sum(fn ($r) => (int) round((float) $r->amount * 100)))->toBe(2000)
            ->and($audits())->toBe(1);

        // same key + same facts, later ⇒ the same row, no new audit
        $same = e1Run(['allocate', (string) $payment->id, (string) $event->id, '20.00', $key]);
        $same->wait();
        expect(trim($same->getOutput()))->toBe('existing:'.$row->id)->and($rows())->toHaveCount(1)->and($audits())->toBe(1);

        // same key + amount 25.00 ⇒ conflict: row count 1, total 20.00, audits 1
        $diff = e1Run(['allocate', (string) $payment->id, (string) $event->id, '25.00', $key]);
        $diff->wait();
        expect(trim($diff->getOutput()))->toBe('conflict')
            ->and($rows())->toHaveCount(1)->and($rows()->sum(fn ($r) => (int) round((float) $r->amount * 100)))->toBe(2000)->and($audits())->toBe(1);

        // the cap is untouched for NEW keys: 80.00 more fits, 0.01 beyond does not
        $fill = e1Run(['allocate', (string) $payment->id, (string) $event->id, '80.00', $key.'-fill']);
        $fill->wait();
        $over = e1Run(['allocate', (string) $payment->id, (string) $event->id, '0.01', $key.'-over']);
        $over->wait();
        expect(trim($fill->getOutput()))->toStartWith('ok:')->and(trim($over->getOutput()))->toBe('rejected:allocation_limit')->and($rows())->toHaveCount(2);
    } finally {
        e1Cleanup($user, $plan);
    }
});

it('mixed concurrent race: 6 requests under ONE key, three at 20.00 and three at 25.00 ⇒ exactly one canonical row (20.00 or 25.00), one audit, the same-amount requests get that row and the others conflict; no second row can exist for the key', function () {
    $user = User::factory()->create(['is_admin' => false]);
    $plan = billingPlan();
    $payment = e1Payment($user, ['amount' => '100.00']);
    $event = e1PeriodEvent($user, $plan);
    $key = 'ui:mixed-'.str()->random(8);

    try {
        $processes = [];
        foreach (['20.00', '25.00', '20.00', '25.00', '20.00', '25.00'] as $amount) {
            $processes[] = e1Run(['allocate', (string) $payment->id, (string) $event->id, $amount, $key]);
        }
        $o = allocOutcomes(e1Outcomes($processes));
        $row = PaymentAllocation::query()->where('customer_payment_id', $payment->id)->sole();

        expect($o['ok'])->toHaveCount(1, json_encode($o))
            ->and($o['existing'])->toHaveCount(2) // the two other requests carrying the winner's amount
            ->and($o['conflict'])->toBe(3) // the three requests carrying the other amount
            ->and($o['rejected'])->toBe([])
            ->and(array_unique([...$o['ok'], ...$o['existing']]))->toBe([(string) $row->id])
            ->and((string) $row->amount)->toBeIn(['20.00', '25.00'])
            ->and(PaymentAllocation::query()->where('idempotency_key', $key)->count())->toBe(1)
            ->and(AuditLog::where('action', 'payment.allocated')->where('subject_id', $payment->id)->count())->toBe(1);
    } finally {
        e1Cleanup($user, $plan);
    }
});

it('refund allocation, service level: 6 concurrent same-key 10.00 attributions ⇒ one row and one audit; replay ⇒ same row; different amount or target under the same key ⇒ conflict; the mixed race yields one canonical row', function () {
    $user = User::factory()->create(['is_admin' => false]);
    $plan = billingPlan();
    $payment = e1Payment($user, ['amount' => '100.00']);
    $event = e1PeriodEvent($user, $plan);
    $service = app(AllocationService::class);
    $allocation = $service->allocatePayment($payment->id, $event->id, '60.00', e1Key());
    $other = $service->allocatePayment($payment->id, $event->id, '40.00', e1Key());
    $refund = e1Refund($payment, ['amount' => '60.00']);
    $key = 'ui:ralloc-'.str()->random(8);
    $rows = fn () => RefundAllocation::query()->where('customer_refund_id', $refund->id)->get();
    $audits = fn () => AuditLog::where('action', 'refund.allocated')->where('subject_id', $payment->id)->count();

    try {
        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = e1Run(['allocate-refund', (string) $refund->id, (string) $allocation->id, '10.00', $key]);
        }
        $o = allocOutcomes(e1Outcomes($processes));
        $row = $rows()->sole();

        expect($o['ok'])->toHaveCount(1, json_encode($o))->and($o['existing'])->toHaveCount(5)->and($o['conflict'])->toBe(0)->and($o['rejected'])->toBe([])
            ->and(array_unique([...$o['ok'], ...$o['existing']]))->toBe([(string) $row->id])
            ->and((string) $row->amount)->toBe('10.00')->and($row->idempotency_key)->toBe($key)->and($audits())->toBe(1);

        $same = e1Run(['allocate-refund', (string) $refund->id, (string) $allocation->id, '10.00', $key]);
        $same->wait();
        $diffAmount = e1Run(['allocate-refund', (string) $refund->id, (string) $allocation->id, '15.00', $key]);
        $diffAmount->wait();
        $diffTarget = e1Run(['allocate-refund', (string) $refund->id, (string) $other->id, '10.00', $key]);
        $diffTarget->wait();
        expect(trim($same->getOutput()))->toBe('existing:'.$row->id)
            ->and(trim($diffAmount->getOutput()))->toBe('conflict')
            ->and(trim($diffTarget->getOutput()))->toBe('conflict')
            ->and($rows())->toHaveCount(1)->and($audits())->toBe(1);

        // mixed race under a second key: 3 × 10.00 and 3 × 15.00 ⇒ one canonical row
        $key2 = 'ui:ralloc-'.str()->random(8);
        $processes = [];
        foreach (['10.00', '15.00', '10.00', '15.00', '10.00', '15.00'] as $amount) {
            $processes[] = e1Run(['allocate-refund', (string) $refund->id, (string) $allocation->id, $amount, $key2]);
        }
        $o = allocOutcomes(e1Outcomes($processes));
        $second = RefundAllocation::query()->where('idempotency_key', $key2)->sole();
        expect($o['ok'])->toHaveCount(1, json_encode($o))->and($o['existing'])->toHaveCount(2)->and($o['conflict'])->toBe(3)
            ->and(array_unique([...$o['ok'], ...$o['existing']]))->toBe([(string) $second->id])
            ->and((string) $second->amount)->toBeIn(['10.00', '15.00'])
            ->and($rows())->toHaveCount(2)->and($audits())->toBe(2);
    } finally {
        e1Cleanup($user, $plan);
    }
});

it('B. UI path (SubmitAttempt claim + keyed service): 6 concurrent claimed submits ⇒ one row; once the claim is released the same key still returns the SAME row and a different payload conflicts — the cache is UX only, the database key is the guarantee', function () {
    $user = User::factory()->create(['is_admin' => false]);
    $plan = billingPlan();
    $payment = e1Payment($user, ['amount' => '100.00']);
    $event = e1PeriodEvent($user, $plan);
    $allocation = app(AllocationService::class)->allocatePayment($payment->id, $event->id, '60.00', e1Key()); // 40.00 of the payment stays allocatable
    $refund = e1Refund($payment, ['amount' => '40.00']);
    $key = 'ui:claimed-'.str()->random(8);
    $rkey = 'ui:rclaimed-'.str()->random(8);

    try {
        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = claimedRun(['allocate-claimed', $key, (string) $payment->id, (string) $event->id, '20.00']);
        }
        $o = allocOutcomes(e1Outcomes($processes));
        $rows = fn () => PaymentAllocation::query()->where('idempotency_key', $key)->get();
        expect(count($o['ok']) + count($o['existing']))->toBeGreaterThanOrEqual(1, json_encode($o))
            ->and($o['conflict'])->toBe(0)->and($rows())->toHaveCount(1)
            ->and(AuditLog::where('action', 'payment.allocated')->where('subject_id', $payment->id)->count())->toBe(2); // the 60.00 setup row + this one

        // claim gone (TTL / flush / restart): the same key is STILL the same row, never a second one; a different amount conflicts
        foreach ([['20.00', 'existing:'.$rows()->sole()->id], ['25.00', 'conflict']] as [$amount, $expected]) {
            $release = claimedRun(['release', 'allocation', $key]);
            $release->wait();
            $p = claimedRun(['allocate-claimed', $key, (string) $payment->id, (string) $event->id, $amount]);
            $p->wait();
            expect(trim($p->getOutput()))->toBe($expected)->and($rows())->toHaveCount(1);
        }

        // refund allocation likewise
        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = claimedRun(['allocate-refund-claimed', $rkey, (string) $refund->id, (string) $allocation->id, '10.00']);
        }
        $o = allocOutcomes(e1Outcomes($processes));
        $rrows = fn () => RefundAllocation::query()->where('idempotency_key', $rkey)->get();
        expect($o['conflict'])->toBe(0)->and($rrows())->toHaveCount(1)
            ->and(AuditLog::where('action', 'refund.allocated')->where('subject_id', $payment->id)->count())->toBe(1);
        foreach ([['10.00', 'existing:'.$rrows()->sole()->id], ['15.00', 'conflict']] as [$amount, $expected]) {
            $release = claimedRun(['release', 'refund_allocation', $rkey]);
            $release->wait();
            $p = claimedRun(['allocate-refund-claimed', $rkey, (string) $refund->id, (string) $allocation->id, $amount]);
            $p->wait();
            expect(trim($p->getOutput()))->toBe($expected)->and($rrows())->toHaveCount(1);
        }
    } finally {
        e1Cleanup($user, $plan);
    }
});
