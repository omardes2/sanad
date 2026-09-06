<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PlanPriceVersionSource;
use App\Enums\SubscriptionEventSource;
use App\Enums\SubscriptionEventType;
use App\Models\Plan;
use App\Models\PlanPriceVersion;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\PlanPriceBook;
use App\Services\Billing\SubscriptionHistory;
use App\Support\Audit\AuditActions;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Phase E0 — the official start of the financial history.
 *
 * For every subscription without a baseline event, records a BASELINE event
 * from NULL to the state found now; for every plan without an open price
 * version, opens one with the plan's current terms. Both stamped with the
 * CAPTURE instant (effective_at / effective_from = now, UTC) — never a past
 * date: nothing before this instant is reconstructed or invented.
 *
 *  - dry-run by default; `--apply` writes;
 *  - idempotent: a second run finds nothing to do;
 *  - concurrency-safe: rows are locked in id order inside one transaction and
 *    the unique baseline_key / one-open-version index are the backstop — a
 *    concurrent run either waits and finds nothing, or hits the unique key and
 *    rolls back cleanly;
 *  - no date option, no scheduler, nothing runs on deploy.
 */
class FinanceHistoryBaselineCommand extends Command
{
    protected $signature = 'sanad:finance:history-baseline {--apply : Write the baseline (default is a dry run)}';

    protected $description = 'Capture the financial history baseline: subscription state events and plan price versions as of NOW (dry-run by default)';

    public function handle(SubscriptionHistory $history, PlanPriceBook $priceBook, AuditLogger $audit): int
    {
        $apply = (bool) $this->option('apply');
        $now = CarbonImmutable::now('UTC');

        $pendingSubscriptions = Subscription::query()
            ->whereNotIn('id', SubscriptionEvent::query()->where('event_type', SubscriptionEventType::Baseline->value)->select('subscription_id'))
            ->orderBy('id')
            ->get(['id', 'subscriber_id', 'status', 'plan_id']);

        $pendingPlans = Plan::query()
            ->whereNotIn('id', PlanPriceVersion::query()->whereNull('effective_until')->select('plan_id'))
            ->orderBy('id')
            ->get(['id', 'slug', 'price', 'currency', 'billing_period']);

        $this->line("Financial history baseline as of {$now->toIso8601String()} (UTC) — effective instants are NOW, never back-dated.");
        $this->table(['Subscriptions needing baseline', 'Plans needing an open price version'], [[$pendingSubscriptions->count(), $pendingPlans->count()]]);

        foreach ($pendingSubscriptions as $subscription) {
            $this->line("  subscription #{$subscription->id}: NULL → {$subscription->status->value} / plan ".($subscription->plan_id ?? 'none'));
        }

        foreach ($pendingPlans as $plan) {
            $this->line("  plan #{$plan->id} [{$plan->slug}]: {$plan->price} {$plan->currency} / {$plan->billing_period->value}");
        }

        if (! $apply) {
            $this->warn('Dry run — nothing written. Re-run with --apply to capture the baseline.');

            return self::SUCCESS;
        }

        if ($pendingSubscriptions->isEmpty() && $pendingPlans->isEmpty()) {
            $this->info('Baseline already captured — nothing written.');

            return self::SUCCESS;
        }

        try {
            [$events, $versions] = DB::transaction(function () use ($history, $priceBook, $audit, $now): array {
                $events = 0;
                $versions = 0;

                // Lock candidates in id order, then re-check under the lock.
                foreach (Subscription::query()->orderBy('id')->lockForUpdate()->get() as $subscription) {
                    $exists = SubscriptionEvent::query()->where('baseline_key', SubscriptionEvent::baselineKeyFor($subscription->id))->exists();

                    if ($exists) {
                        continue;
                    }

                    $history->record(
                        $subscription,
                        SubscriptionEventType::Baseline,
                        null,
                        null,
                        null,
                        null,
                        SubscriptionEventSource::Baseline,
                        $now,
                        'financial history baseline',
                        [],
                        null,
                        SubscriptionEvent::baselineKeyFor($subscription->id),
                    );
                    $events++;
                }

                foreach (Plan::query()->orderBy('id')->lockForUpdate()->get() as $plan) {
                    if ($priceBook->openVersionFor($plan->id) !== null) {
                        continue;
                    }

                    $priceBook->recordVersion($plan, $now, PlanPriceVersionSource::Baseline);
                    $versions++;
                }

                if ($events > 0 || $versions > 0) {
                    $audit->record(AuditActions::FinanceHistoryBaselineApplied, null, [], [
                        'captured_at' => $now->toIso8601String(),
                        'subscription_events' => $events,
                        'plan_price_versions' => $versions,
                    ]);
                }

                return [$events, $versions];
            });
        } catch (UniqueConstraintViolationException) {
            $this->info('Baseline was captured concurrently by another run — nothing written.');

            return self::SUCCESS;
        }

        if ($events === 0 && $versions === 0) {
            $this->info('Baseline already captured (by a concurrent run) — nothing written.');

            return self::SUCCESS;
        }

        $this->info("Captured {$events} baseline event(s) and {$versions} plan price version(s) effective {$now->toIso8601String()}.");

        return self::SUCCESS;
    }
}
