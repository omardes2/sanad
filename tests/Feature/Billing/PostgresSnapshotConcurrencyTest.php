<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\FinanceMrrSnapshot;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Audit\AuditActions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * GENUINE parallel test for the Phase D1 MRR snapshot: several OS processes run
 * `sanad:finance:snapshot` at the same instant against the same PostgreSQL
 * database. The rows and the audit entry are inserted in ONE transaction under
 * a unique (date, currency, plan) key, so exactly one complete set exists
 * afterwards, every process exits 0, and nothing is ever rewritten.
 *
 * Runs only on a reachable pgsql connection. Not wrapped in RefreshDatabase;
 * it removes today's snapshot rows before and after and cleans its own rows.
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

it('concurrent snapshot runs leave exactly one complete set of rows and one audit entry', function () {
    $today = CarbonImmutable::now('UTC')->toDateString();

    $plan = Plan::create(['name' => 'PG race', 'slug' => 'pg-race-'.str()->random(6), 'price' => '10.00', 'currency' => 'USD', 'billing_period' => 'monthly', 'trial_days' => 0, 'limits' => [], 'features' => [], 'is_active' => true, 'is_default' => false, 'sort_order' => 99]);
    $subscribers = [];

    for ($i = 0; $i < 3; $i++) {
        $user = User::factory()->create(['is_admin' => false]);
        Subscription::create(['subscriber_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active', 'started_at' => now(), 'current_period_start' => now(), 'current_period_end' => now()->addMonth()]);
        $subscribers[] = $user;
    }

    FinanceMrrSnapshot::query()->where('snapshot_date', $today)->delete();
    AuditLog::where('action', AuditActions::FinanceMrrSnapshotCaptured)->delete();

    try {
        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $p = new Process(['php', 'artisan', 'sanad:finance:snapshot'], base_path());
            $p->start();
            $processes[] = $p;
        }

        $captured = 0;
        $noop = 0;
        foreach ($processes as $p) {
            $p->wait();
            expect($p->getExitCode())->toBe(0, $p->getOutput().$p->getErrorOutput());
            if (str_contains($p->getOutput(), 'Captured ')) {
                $captured++;
            } elseif (str_contains($p->getOutput(), 'nothing written')) {
                $noop++;
            }
        }

        $rows = FinanceMrrSnapshot::query()->where('snapshot_date', $today)->get();
        $planRows = $rows->where('plan_key', (string) $plan->id);

        expect($captured)->toBe(1)
            ->and($noop)->toBe(5)
            ->and($planRows)->toHaveCount(1)
            ->and($planRows->first()->active_count)->toBe(3)
            ->and((string) $planRows->first()->mrr_normalized)->toBe('30.000000')
            ->and($rows->groupBy(fn ($r) => $r->currency.'|'.$r->plan_key)->filter(fn ($g) => $g->count() > 1))->toHaveCount(0)
            ->and(AuditLog::where('action', AuditActions::FinanceMrrSnapshotCaptured)->count())->toBe(1);
    } finally {
        FinanceMrrSnapshot::query()->where('snapshot_date', $today)->delete();
        AuditLog::where('action', AuditActions::FinanceMrrSnapshotCaptured)->delete();
        foreach ($subscribers as $user) {
            $user->delete(); // subscription cascades
        }
        $plan->delete();
    }
});
