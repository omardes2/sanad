<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\Finance\MrrSnapshotSet;
use App\Models\FinanceMrrSnapshot;
use App\Services\Audit\AuditLogger;
use App\Services\Finance\MrrCalculator;
use App\Support\Audit\AuditActions;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Captures TODAY's (UTC) Calculated MRR snapshot rows — one per currency and
 * plan — from the current subscriptions and plan prices.
 *
 * Deliberately narrow:
 *  - only the current UTC date: there is no date argument, no back-dating and
 *    no back-fill (a day that was not captured stays NOT AVAILABLE forever);
 *  - idempotent: a second run on the same day writes nothing and says so; an
 *    existing day is never rewritten, silently or otherwise;
 *  - atomic: all rows and the audit entry are inserted in one transaction, so
 *    two concurrent runs leave exactly one complete set (the loser hits the
 *    unique key, rolls back and reports "already captured");
 *  - manual: nothing schedules it in Phase D1.
 */
class FinanceSnapshotCommand extends Command
{
    protected $signature = 'sanad:finance:snapshot {--dry-run : Show what would be captured without writing}';

    protected $description = 'Capture today\'s (UTC) Calculated MRR snapshot rows (idempotent; never back-dates)';

    public function handle(MrrCalculator $calculator, AuditLogger $audit): int
    {
        $now = CarbonImmutable::now('UTC');
        $date = $now->toDateString();

        $existing = FinanceMrrSnapshot::query()->where('snapshot_date', $date)->count();

        if ($existing > 0) {
            $this->info("Snapshot for {$date} (UTC) already captured ({$existing} row(s)) — nothing written.");

            return self::SUCCESS;
        }

        $set = $calculator->current($now);
        $rows = $this->rowsFor($set, $date, $now);

        $this->render($set, $date);

        if ($this->option('dry-run')) {
            $this->warn('Dry run — nothing written.');

            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($rows, $set, $date, $audit): void {
                foreach ($rows as $row) {
                    FinanceMrrSnapshot::query()->create($row);
                }

                $audit->record(AuditActions::FinanceMrrSnapshotCaptured, null, [], [
                    'snapshot_date' => $date,
                    'rows' => count($rows),
                    'currencies' => array_keys($set->byCurrency()),
                    'calculation_version' => $set->calculationVersion,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            $count = FinanceMrrSnapshot::query()->where('snapshot_date', $date)->count();
            $this->info("Snapshot for {$date} (UTC) was captured concurrently by another run ({$count} row(s)) — nothing written.");

            return self::SUCCESS;
        }

        $this->info('Captured '.count($rows)." snapshot row(s) for {$date} (UTC).");

        return self::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsFor(MrrSnapshotSet $set, string $date, CarbonImmutable $capturedAt): array
    {
        $rows = [];

        foreach ($set->rows as $row) {
            $rows[] = [
                'snapshot_date' => $date,
                'captured_at' => $capturedAt,
                'currency' => $row->currency,
                'plan_id' => $row->planId,
                'plan_key' => $row->planKey,
                'plan_slug' => $row->planSlug,
                'plan_price' => $row->planPrice,
                'billing_period' => $row->billingPeriod,
                'active_count' => $row->activeCount,
                'trialing_count' => $row->trialingCount,
                'past_due_count' => $row->pastDueCount,
                'mrr_normalized' => $row->mrrNormalized,
                'calculation_version' => $set->calculationVersion,
            ];
        }

        if ($rows === []) {
            // Mark the day as captured even when nothing is subscribed, so a
            // later run today cannot invent a different picture for it.
            $rows[] = [
                'snapshot_date' => $date,
                'captured_at' => $capturedAt,
                'currency' => FinanceMrrSnapshot::NO_CURRENCY,
                'plan_id' => null,
                'plan_key' => FinanceMrrSnapshot::PLAN_KEY_NONE,
                'plan_slug' => null,
                'plan_price' => null,
                'billing_period' => null,
                'active_count' => 0,
                'trialing_count' => 0,
                'past_due_count' => 0,
                'mrr_normalized' => '0.000000',
                'calculation_version' => $set->calculationVersion,
            ];
        }

        return $rows;
    }

    private function render(MrrSnapshotSet $set, string $date): void
    {
        $this->line("Calculated MRR snapshot for {$date} (UTC), as of {$set->asOf->toIso8601String()}, calculation v{$set->calculationVersion}");
        $this->line('Calculated (list price × active) — NOT collected revenue.');

        $this->table(
            ['Currency', 'Plan', 'Price', 'Period', 'Active', 'Trialing', 'Past due', 'MRR (normalized)'],
            array_map(static fn ($r) => [$r->currency, $r->planSlug ?? '(none)', $r->planPrice ?? '-', $r->billingPeriod ?? '-', $r->activeCount, $r->trialingCount, $r->pastDueCount, $r->mrrNormalized], $set->rows),
        );

        foreach ($set->byCurrency() as $currency => $totals) {
            $this->line("{$currency}: MRR {$totals['mrr']} · ARR {$totals['arr']} · ARPU ".($totals['arpu'] ?? 'n/a')." · active {$totals['active']} · trialing {$totals['trialing']} · past_due {$totals['past_due']} (past_due is NOT in MRR)");
        }

        $unassigned = $set->unassigned();
        $this->line("No plan (marker, not a currency, never revenue): active {$unassigned['active']} · trialing {$unassigned['trialing']} · past_due {$unassigned['past_due']}");
    }
}
