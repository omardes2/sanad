<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Data\Billing\Entitlement;
use App\Enums\PlanFeature;
use App\Enums\SubscriptionEventSource;
use App\Enums\SubscriptionEventType;
use App\Enums\SubscriptionStatus;
use App\Enums\UsageDimension;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Settings\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Channel-agnostic subscription domain service. Knows nothing about WhatsApp —
 * any channel (WhatsApp today; app/web/calls later) calls the same methods.
 *
 * Phase E0: every mutation runs in ONE transaction that locks the subscription
 * row, saves the new state, appends a subscription_events row and writes the
 * audit entry — a failure anywhere rolls all of it back, so the history can
 * never disagree with the state.
 */
class SubscriptionService
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly SubscriptionHistory $history,
    ) {}

    /**
     * Assign the configured default plan to a brand-new subscriber, once.
     *
     * Safe to disable (billing.auto_trial). A subscriber that already has a
     * subscription is returned untouched, so repeated onboarding can never grant
     * a second free trial. Race-safe via the unique(subscriber_id) constraint.
     */
    public function assignDefaultIfEnabled(User $subscriber): ?Subscription
    {
        if (! $this->settings->get('billing.auto_trial')) {
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
            return DB::transaction(function () use ($subscriber, $attributes, $now): Subscription {
                $subscription = Subscription::create(['subscriber_id' => $subscriber->id] + $attributes);

                $this->history->record($subscription, SubscriptionEventType::Activated, null, null, SubscriptionEventSource::Onboarding, $now, 'default plan on onboarding', [
                    'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
                    'current_period_end' => $subscription->current_period_end?->toIso8601String(),
                ]);

                return $subscription;
            });
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
        $slug = (string) $this->settings->get('billing.default_plan_slug');

        return Plan::query()->where('is_active', true)->where('slug', $slug)->first()
            ?? Plan::query()->where('is_active', true)->where('is_default', true)->first();
    }

    // ---- Manual admin operations -----------------------------------------

    /**
     * Assign (or re-assign) a plan to a subscriber and activate: creates the
     * subscription when the subscriber has none (from_* NULL in the event).
     */
    public function activateFor(User $subscriber, Plan $plan, SubscriptionEventSource $source = SubscriptionEventSource::Admin): Subscription
    {
        return DB::transaction(function () use ($subscriber, $plan, $source): Subscription {
            $existing = Subscription::query()->where('subscriber_id', $subscriber->id)->lockForUpdate()->first();

            if ($existing !== null) {
                return $this->activateLocked($existing, $plan, $source);
            }

            $now = CarbonImmutable::now();
            $subscription = Subscription::create([
                'subscriber_id' => $subscriber->id,
                'plan_id' => $plan->id,
                'status' => SubscriptionStatus::Active,
                'started_at' => $now,
                'current_period_start' => $now,
                'current_period_end' => $plan->billing_period->endFrom($now),
                'renews_at' => $plan->billing_period->endFrom($now),
            ]);

            $this->history->record($subscription, SubscriptionEventType::Activated, null, null, $source, $now, 'plan assigned');

            return $subscription;
        });
    }

    public function activate(Subscription $subscription, ?Plan $plan = null, SubscriptionEventSource $source = SubscriptionEventSource::Admin): Subscription
    {
        return DB::transaction(function () use ($subscription, $plan, $source): Subscription {
            $locked = $this->lock($subscription);

            return $this->activateLocked($locked, $plan ?? $locked->plan, $source);
        });
    }

    public function suspend(Subscription $subscription, SubscriptionEventSource $source = SubscriptionEventSource::Admin, ?string $reason = null): Subscription
    {
        return DB::transaction(function () use ($subscription, $source, $reason): Subscription {
            $locked = $this->lock($subscription);
            $fromStatus = $locked->status;
            $now = CarbonImmutable::now();

            $locked->forceFill(['status' => SubscriptionStatus::Suspended])->save();
            $this->history->record($locked, SubscriptionEventType::Suspended, $fromStatus, $locked->plan_id, $source, $now, $reason);

            return $this->sync($subscription, $locked);
        });
    }

    public function cancel(Subscription $subscription, SubscriptionEventSource $source = SubscriptionEventSource::Admin, ?string $reason = null): Subscription
    {
        return DB::transaction(function () use ($subscription, $source, $reason): Subscription {
            $locked = $this->lock($subscription);
            $fromStatus = $locked->status;
            $now = CarbonImmutable::now();

            $locked->forceFill(['status' => SubscriptionStatus::Cancelled, 'cancelled_at' => $now])->save();
            $this->history->record($locked, SubscriptionEventType::Cancelled, $fromStatus, $locked->plan_id, $source, $now, $reason);

            return $this->sync($subscription, $locked);
        });
    }

    /**
     * Extend the current period (and any trial) by N days and keep access on.
     */
    public function extend(Subscription $subscription, int $days, SubscriptionEventSource $source = SubscriptionEventSource::Admin, ?string $reason = null): Subscription
    {
        return DB::transaction(function () use ($subscription, $days, $source, $reason): Subscription {
            $locked = $this->lock($subscription);
            $fromStatus = $locked->status;
            $now = CarbonImmutable::now();
            $previousEnd = $locked->current_period_end;

            $base = $previousEnd ?? $now;
            $end = CarbonImmutable::parse($base)->addDays($days);

            $locked->forceFill([
                'status' => $locked->status->isEntitled() ? $locked->status : SubscriptionStatus::Active,
                'current_period_end' => $end,
                'renews_at' => $end,
                'trial_ends_at' => $locked->status === SubscriptionStatus::Trialing
                    ? CarbonImmutable::parse($locked->trial_ends_at ?? $now)->addDays($days)
                    : $locked->trial_ends_at,
            ])->save();

            $this->history->record($locked, SubscriptionEventType::Extended, $fromStatus, $locked->plan_id, $source, $now, $reason, [
                'days' => $days,
                'current_period_end' => ['from' => $previousEnd?->toIso8601String(), 'to' => $end->toIso8601String()],
            ]);

            return $this->sync($subscription, $locked);
        });
    }

    private function activateLocked(Subscription $locked, ?Plan $plan, SubscriptionEventSource $source): Subscription
    {
        $now = CarbonImmutable::now();
        $fromStatus = $locked->status;
        $fromPlanId = $locked->plan_id;
        $plan ??= $locked->plan;

        $locked->forceFill([
            'plan_id' => $plan?->id ?? $locked->plan_id,
            'status' => SubscriptionStatus::Active,
            'started_at' => $locked->started_at ?? $now,
            'current_period_start' => $now,
            'current_period_end' => $plan?->billing_period->endFrom($now),
            'renews_at' => $plan?->billing_period->endFrom($now),
            'cancelled_at' => null,
        ])->save();

        $this->history->record($locked, SubscriptionEventType::Activated, $fromStatus, $fromPlanId, $source, $now);

        if ($fromPlanId !== $locked->plan_id) {
            $this->history->record($locked, SubscriptionEventType::PlanChanged, SubscriptionStatus::Active, $fromPlanId, $source, $now, 'plan changed on activation');
        }

        return $locked;
    }

    /** Re-read the row under a row lock so concurrent mutations serialise. */
    private function lock(Subscription $subscription): Subscription
    {
        return Subscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();
    }

    /** Reflect the committed state on the caller's instance and return it. */
    private function sync(Subscription $caller, Subscription $locked): Subscription
    {
        $caller->setRawAttributes($locked->getAttributes(), true);

        return $caller;
    }
}
