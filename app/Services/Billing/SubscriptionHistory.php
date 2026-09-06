<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\SubscriptionEventSource;
use App\Enums\SubscriptionEventType;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Services\Audit\AuditLogger;
use App\Support\Audit\AuditActions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;

/**
 * The only writer of subscription_events (Phase E0). Must be called INSIDE
 * the transaction that changes the subscription, after the row was locked and
 * saved: the event and its audit entry then commit or roll back with the state.
 */
final class SubscriptionHistory
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        Subscription $subscription,
        SubscriptionEventType $type,
        ?SubscriptionStatus $fromStatus,
        ?int $fromPlanId,
        SubscriptionEventSource $source,
        CarbonImmutable $effectiveAt,
        ?string $reason = null,
        array $metadata = [],
        ?string $correlationId = null,
        ?string $baselineKey = null,
    ): SubscriptionEvent {
        $event = SubscriptionEvent::query()->create([
            'subscription_id' => $subscription->id,
            'subscriber_id' => $subscription->subscriber_id,
            'event_type' => $type->value,
            'from_status' => $fromStatus?->value,
            'to_status' => $subscription->status->value,
            'from_plan_id' => $fromPlanId,
            'to_plan_id' => $subscription->plan_id,
            'effective_at' => $effectiveAt,
            'source' => $source->value,
            'actor_ref' => self::actorRef(),
            'reason' => $reason,
            'correlation_id' => $correlationId,
            'metadata' => $metadata === [] ? null : $metadata,
            'baseline_key' => $baselineKey,
            'created_at' => $effectiveAt,
        ]);

        // Atomic with the transition: an audit failure rolls state and event back.
        $this->audit->record(AuditActions::SubscriptionTransitioned, $subscription, [
            'status' => ['from' => $fromStatus?->value, 'to' => $subscription->status->value],
            'plan_id' => ['from' => $fromPlanId, 'to' => $subscription->plan_id],
        ], [
            'event_type' => $type->value,
            'event_id' => $event->id,
            'source' => $source->value,
            'subscriber_id' => $subscription->subscriber_id,
            'reason' => $reason,
        ]);

        return $event;
    }

    public static function actorRef(): string
    {
        $user = Auth::user();

        if ($user !== null) {
            return 'user:'.$user->getAuthIdentifier();
        }

        return app()->runningInConsole() ? 'console' : 'system';
    }
}
