<?php

declare(strict_types=1);

use App\Enums\SubscriptionStatus;
use App\Enums\UsageDimension;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCharge;
use App\Models\UsageCounter;
use App\Models\User;
use App\Services\Billing\UsageEngine;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * A GENUINE parallel test: it launches many concurrent OS processes that each
 * charge the same subscriber against the SAME PostgreSQL database, proving the
 * atomic upsert (unique constraint + conditional increment) never exceeds the
 * hard cap and never double-charges — including when the counter row does not
 * exist yet (the case SELECT ... FOR UPDATE cannot cover).
 *
 * It runs only when the default connection is PostgreSQL and reachable; on the
 * SQLite in-memory test DB (CI + default local `php artisan test`) it is skipped
 * — a single :memory: database cannot be shared across processes. Not wrapped in
 * RefreshDatabase; it creates and then removes only the rows it made.
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

it('never exceeds the daily cap under real concurrent processes (row-creation race included)', function () {
    $cap = 5;
    $processCount = 20;

    $plan = Plan::create([
        'name' => 'Concurrency', 'slug' => 'concurrency-'.uniqid(),
        'price' => 0, 'currency' => 'ILS', 'billing_period' => 'monthly', 'trial_days' => 0,
        'limits' => ['ai_reply' => ['daily' => $cap, 'monthly' => 1000, 'weight' => 1]],
        'features' => [], 'is_active' => true, 'is_default' => false, 'sort_order' => 0,
    ]);

    $subscriber = User::create([
        'name' => 'Concurrency Subscriber', 'is_admin' => false,
        'phone' => '+972590'.random_int(100000, 999999),
    ]);

    Subscription::create([
        'subscriber_id' => $subscriber->id, 'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active, 'started_at' => now(),
        'current_period_start' => now(), 'current_period_end' => now()->addMonth(),
    ]);

    try {
        // Launch all probes as close to simultaneously as possible — the counter
        // row does NOT exist yet, so the first arrivals race to create it.
        $processes = [];
        for ($i = 0; $i < $processCount; $i++) {
            $p = new Process(['php', 'artisan', 'sanad:usage-charge-probe', (string) $subscriber->id, "pg-key-{$i}"], base_path());
            $p->start();
            $processes[] = $p;
        }

        $allowed = 0;
        foreach ($processes as $p) {
            $p->wait();
            if (trim($p->getOutput()) === 'allowed') {
                $allowed++;
            }
        }

        $counterUsed = app(UsageEngine::class)->usage($subscriber->fresh(), UsageDimension::AiReply)['daily'];
        $chargeRows = UsageCharge::where('subscriber_id', $subscriber->id)->count();

        // The invariant: exactly `cap` charges succeeded, the counter equals the
        // cap (never more), and the charge log has exactly `cap` rows.
        expect($allowed)->toBe($cap)
            ->and($counterUsed)->toBe($cap)
            ->and($chargeRows)->toBe($cap);
    } finally {
        UsageCharge::where('subscriber_id', $subscriber->id)->delete();
        UsageCounter::where('subscriber_id', $subscriber->id)->delete();
        Subscription::where('subscriber_id', $subscriber->id)->delete();
        User::whereKey($subscriber->id)->delete();
        Plan::whereKey($plan->id)->delete();
    }
});

it('does not double-charge duplicate keys under real concurrent processes', function () {
    $plan = Plan::create([
        'name' => 'Concurrency Dup', 'slug' => 'concurrency-dup-'.uniqid(),
        'price' => 0, 'currency' => 'ILS', 'billing_period' => 'monthly', 'trial_days' => 0,
        'limits' => ['ai_reply' => ['daily' => 100, 'monthly' => 1000, 'weight' => 1]],
        'features' => [], 'is_active' => true, 'is_default' => false, 'sort_order' => 0,
    ]);

    $subscriber = User::create([
        'name' => 'Dup Subscriber', 'is_admin' => false,
        'phone' => '+972590'.random_int(100000, 999999),
    ]);

    Subscription::create([
        'subscriber_id' => $subscriber->id, 'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active, 'started_at' => now(),
        'current_period_start' => now(), 'current_period_end' => now()->addMonth(),
    ]);

    try {
        // 10 processes all charging the SAME idempotency key at once.
        $processes = [];
        for ($i = 0; $i < 10; $i++) {
            $p = new Process(['php', 'artisan', 'sanad:usage-charge-probe', (string) $subscriber->id, 'same-dup-key'], base_path());
            $p->start();
            $processes[] = $p;
        }
        foreach ($processes as $p) {
            $p->wait();
        }

        expect(app(UsageEngine::class)->usage($subscriber->fresh(), UsageDimension::AiReply)['daily'])->toBe(1)
            ->and(UsageCharge::where('idempotency_key', 'same-dup-key')->count())->toBe(1);
    } finally {
        UsageCharge::where('subscriber_id', $subscriber->id)->delete();
        UsageCounter::where('subscriber_id', $subscriber->id)->delete();
        Subscription::where('subscriber_id', $subscriber->id)->delete();
        User::whereKey($subscriber->id)->delete();
        Plan::whereKey($plan->id)->delete();
    }
});
