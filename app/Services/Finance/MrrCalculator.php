<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Data\Finance\MrrPlanRow;
use App\Data\Finance\MrrSnapshotSet;
use App\Enums\BillingPeriod;
use App\Enums\SubscriptionStatus;
use App\Models\FinanceMrrSnapshot;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Billing\DecimalMath;
use Carbon\CarbonImmutable;

/**
 * Current Calculated MRR / ARR / ARPU ("as of now") from the subscriptions
 * table — the only revenue figure Phase D can state, because there is no
 * subscription history and no plan-price history to rebuild the past from.
 *
 * Counting rules (calculation_version 1):
 *  - active_count   = status `active` whose current_period_end is NULL or in
 *                     the future (a lapsed period does not earn MRR);
 *  - trialing_count = status `trialing` (never MRR);
 *  - past_due_count = status `past_due` (billed, not paid — never MRR);
 *  - MRR            = monthly-equivalent list price × active_count, grouped by
 *                     the plan's currency; currencies are never summed together;
 *  - subscriptions without a plan are counted under plan_key "none" with
 *    currency XXX (ISO 4217 "no currency") and contribute nothing: that row
 *    is a marker, excluded from every per-currency figure.
 *
 * plan_key is "plan:<id>" (FinanceMrrSnapshot::planKeyFor) — identity never
 * depends on the slug or any other mutable attribute.
 */
final class MrrCalculator
{
    public const VERSION = 1;

    public function current(?CarbonImmutable $asOf = null): MrrSnapshotSet
    {
        $asOf ??= CarbonImmutable::now('UTC');

        $counts = [];

        $rows = Subscription::query()
            ->toBase()
            ->selectRaw('plan_id, status, COUNT(*) AS n')
            ->where(function ($q) use ($asOf): void {
                $q->whereIn('status', [SubscriptionStatus::Trialing->value, SubscriptionStatus::PastDue->value])
                    ->orWhere(function ($active) use ($asOf): void {
                        $active->where('status', SubscriptionStatus::Active->value)
                            ->where(function ($period) use ($asOf): void {
                                $period->whereNull('current_period_end')->orWhere('current_period_end', '>', $asOf);
                            });
                    });
            })
            ->groupBy('plan_id', 'status')
            ->get();

        foreach ($rows as $row) {
            // 0 = "no plan"; real ids are ≥ 1.
            $id = $row->plan_id === null ? 0 : DecimalMath::intFromDb($row->plan_id);
            $counts[$id][(string) $row->status] = DecimalMath::intFromDb($row->n);
        }

        $planIds = array_values(array_filter(array_keys($counts), static fn (int $id) => $id > 0));
        $plans = Plan::query()->whereIn('id', $planIds)->get()->keyBy('id');

        $result = [];

        foreach ($counts as $id => $byStatus) {
            $active = $byStatus[SubscriptionStatus::Active->value] ?? 0;
            $trialing = $byStatus[SubscriptionStatus::Trialing->value] ?? 0;
            $pastDue = $byStatus[SubscriptionStatus::PastDue->value] ?? 0;

            /** @var Plan|null $plan */
            $plan = $id === 0 ? null : $plans->get($id);

            if ($plan === null) {
                // No plan (or the plan row is gone): counted, never revenue.
                $result[] = new MrrPlanRow(
                    currency: FinanceMrrSnapshot::NO_CURRENCY,
                    planId: $id === 0 ? null : $id,
                    planKey: FinanceMrrSnapshot::planKeyFor($id === 0 ? null : $id),
                    planSlug: null,
                    planPrice: null,
                    billingPeriod: null,
                    activeCount: $active,
                    trialingCount: $trialing,
                    pastDueCount: $pastDue,
                    mrrNormalized: DecimalMath::format(0, RevenueNormalizer::SCALE),
                );

                continue;
            }

            $price = (string) $plan->price;
            $period = $plan->billing_period instanceof BillingPeriod ? $plan->billing_period : BillingPeriod::from((string) $plan->billing_period);
            $monthly = RevenueNormalizer::monthly($price, $period);

            $result[] = new MrrPlanRow(
                currency: strtoupper($plan->currency),
                planId: $plan->id,
                planKey: FinanceMrrSnapshot::planKeyFor($plan->id),
                planSlug: $plan->slug,
                planPrice: $price,
                billingPeriod: $period->value,
                activeCount: $active,
                trialingCount: $trialing,
                pastDueCount: $pastDue,
                mrrNormalized: RevenueNormalizer::times($monthly, $active),
            );
        }

        usort($result, static fn (MrrPlanRow $a, MrrPlanRow $b): int => [$a->currency, $a->planKey] <=> [$b->currency, $b->planKey]);

        return new MrrSnapshotSet($asOf, self::VERSION, $result);
    }
}
