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
 *  - active subscriptions without a plan are counted under plan_key "none"
 *    with currency XXX and contribute nothing.
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
            $key = $row->plan_id === null ? FinanceMrrSnapshot::PLAN_KEY_NONE : (string) DecimalMath::intFromDb($row->plan_id);
            $counts[$key][(string) $row->status] = DecimalMath::intFromDb($row->n);
        }

        $planIds = array_values(array_filter(array_keys($counts), static fn (string $k) => $k !== FinanceMrrSnapshot::PLAN_KEY_NONE));
        $plans = Plan::query()->whereIn('id', $planIds)->get()->keyBy('id');

        $result = [];

        foreach ($counts as $key => $byStatus) {
            $active = $byStatus[SubscriptionStatus::Active->value] ?? 0;
            $trialing = $byStatus[SubscriptionStatus::Trialing->value] ?? 0;
            $pastDue = $byStatus[SubscriptionStatus::PastDue->value] ?? 0;

            /** @var Plan|null $plan */
            $plan = $key === FinanceMrrSnapshot::PLAN_KEY_NONE ? null : $plans->get((int) $key);

            if ($plan === null) {
                // No plan (or the plan row is gone): counted, never revenue.
                $result[] = new MrrPlanRow(
                    currency: FinanceMrrSnapshot::NO_CURRENCY,
                    planId: $key === FinanceMrrSnapshot::PLAN_KEY_NONE ? null : (int) $key,
                    planKey: (string) $key,
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
                planKey: (string) $plan->id,
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
