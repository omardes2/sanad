<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Data\Billing\Entitlement;
use App\Enums\PlanFeature;
use App\Enums\SubscriptionStatus;
use App\Enums\UsageDimension;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Channel-agnostic subscription domain service. Knows nothing about WhatsApp —
 * any channel (WhatsApp today; app/web/calls later) calls the same methods.
 */
class SubscriptionService
{
    /**
     * Assign the configured default plan to a brand-new subscriber, once.
     *
     * Safe to disable (billing.auto_trial). A subscriber that already has a
     * subscription is returned untouched, so repeated onboarding can never grant
     * a second free trial. Race-safe via the unique(subscriber_id) constraint.
     */
    public function assignDefaultIfEnabled(User $subscriber): ?Subscription
    {
        if (! config('billing.auto_trial', true)) {
            return null;
        }

        if ($subscriber->subscription()->exists()) {
            return $subscriber->subscription;
        }

        $plan = $this->defaultPlan();

        if ($plan === null) {
            return null;
        }

        $now = CarbonImmutable::now();
        $trialing = $plan->trial_days > 0;

        $attributes = [
            'plan_id' => $plan->id,
            'status' => $trialing ? SubscriptionStatus::Trialing : SubscriptionStatus::Active,
            'started_at' => $now,
            'trial_ends_at' => $trialing ? $now->addDays($plan->trial_days) : null,
            'current_period_start' => $now,
            'current_period_end' => $plan->billing_period->endFrom($now),
            'renews_at' => $plan->billing_period->endFrom($now),
        ];

        try {
            return Subscription::create(['subscriber_id' => $subscriber->id] + $attributes);
        } catch (UniqueConstraintViolationException) {
            // A concurrent onboarding created it first — reuse, never a 2nd trial.
            return $subscriber->subscription()->firstOrFail();
        }
    }

    /**
     * The subscriber's current plan if their subscription entitles them, else null.
     */
    public function currentPlan(User $subscriber): ?Plan
    {
        $subscription = $subscriber->subscription;

        if ($subscription === null || ! $subscription->isEntitled()) {
            return null;
        }

        return $subscription->plan;
    }

    /**
     * Resolve the subscriber's allowance for a dimension under their current plan.
     */
    public function entitlement(User $subscriber, UsageDimension $dimension): Entitlement
    {
        $plan = $this->currentPlan($subscriber);

        if ($plan === null) {
            return Entitlement::disabled();
        }

        $limit = $plan->limitFor($dimension);

        if ($limit === null) {
            return Entitlement::disabled();
        }

        return new Entitlement(
            entitled: true,
            dailyLimit: $limit['daily'],
            monthlyLimit: $limit['monthly'],
            weight: $limit['weight'],
        );
    }

    /**
     * Whether a non-metered capability is available to the subscriber under
     * their current plan. Channel-agnostic and enforcement-independent: a
     * subscriber with no entitled plan gets nothing.
     *
     * Enforcement note: like entitlement(), this only reports the plan's
     * entitlement. When billing.enforce is off, callers may choose to ignore it
     * (the app behaves as before); when on, gate the capability on this.
     */
    public function hasFeature(User $subscriber, PlanFeature $feature): bool
    {
        return $this->currentPlan($subscriber)?->hasFeature($feature) ?? false;
    }

    /**
     * The exact feature value (tier or boolean) under the subscriber's current
     * plan, or the feature's own default when they have no entitled plan.
     */
    public function featureValue(User $subscriber, PlanFeature $feature): bool|string
    {
        return $this->currentPlan($subscriber)?->featureValue($feature) ?? $feature->default();
    }

    public function defaultPlan(): ?Plan
    {
        $slug = (string) config('billing.default_plan_slug', 'free');

        return Plan::query()->where('is_active', true)->where('slug', $slug)->first()
            ?? Plan::query()->where('is_active', true)->where('is_default', true)->first();
    }

    // ---- Manual admin operations -----------------------------------------

    public function activate(Subscription $subscription, ?Plan $plan = null): Subscription
    {
        $now = CarbonImmutable::now();
        $plan ??= $subscription->plan;

        $subscription->forceFill([
            'plan_id' => $plan?->id ?? $subscription->plan_id,
            'status' => SubscriptionStatus::Active,
            'started_at' => $subscription->started_at ?? $now,
            'current_period_start' => $now,
            'current_period_end' => $plan?->billing_period->endFrom($now),
            'renews_at' => $plan?->billing_period->endFrom($now),
            'cancelled_at' => null,
        ])->save();

        return $subscription;
    }

    public function suspend(Subscription $subscription): Subscription
    {
        $subscription->forceFill(['status' => SubscriptionStatus::Suspended])->save();

        return $subscription;
    }

    public function cancel(Subscription $subscription): Subscription
    {
        $subscription->forceFill([
            'status' => SubscriptionStatus::Cancelled,
            'cancelled_at' => CarbonImmutable::now(),
        ])->save();

        return $subscription;
    }

    /**
     * Extend the current period (and any trial) by N days and keep access on.
     */
    public function extend(Subscription $subscription, int $days): Subscription
    {
        $base = $subscription->current_period_end ?? CarbonImmutable::now();
        $end = CarbonImmutable::parse($base)->addDays($days);

        $subscription->forceFill([
            'status' => $subscription->status->isEntitled() ? $subscription->status : SubscriptionStatus::Active,
            'current_period_end' => $end,
            'renews_at' => $end,
            'trial_ends_at' => $subscription->status === SubscriptionStatus::Trialing
                ? CarbonImmutable::parse($subscription->trial_ends_at ?? CarbonImmutable::now())->addDays($days)
                : $subscription->trial_ends_at,
        ])->save();

        return $subscription;
    }
}
