<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Models\Subscription;
use App\Models\SubscriptionEvent;

/**
 * Opaque fingerprint of the subscription state an admin looked at (Phase E0
 * stale protection, same principle as the C3/C4 expected-state checks).
 *
 * It covers the projection (status, plan, period, trial, cancellation) AND the
 * id of the latest history event, so ANY committed transition — even one that
 * leaves the projection identical — changes the token. A mutation carrying a
 * token that no longer matches is refused before anything is written.
 */
final class SubscriptionStateToken
{
    /** The token for "the admin saw no subscription at all". */
    public const NONE = 'none';

    public static function for(Subscription $subscription): string
    {
        $lastEventId = SubscriptionEvent::query()->where('subscription_id', $subscription->id)->max('id');

        $state = [
            'id' => $subscription->id,
            'status' => $subscription->status->value,
            'plan_id' => $subscription->plan_id,
            'period_start' => $subscription->current_period_start?->toIso8601String(),
            'period_end' => $subscription->current_period_end?->toIso8601String(),
            'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
            'cancelled_at' => $subscription->cancelled_at?->toIso8601String(),
            'last_event_id' => $lastEventId === null ? null : (int) $lastEventId,
        ];

        return substr(hash('sha256', json_encode($state, JSON_THROW_ON_ERROR)), 0, 16);
    }
}
