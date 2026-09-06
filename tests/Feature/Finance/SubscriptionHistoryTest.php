<?php

declare(strict_types=1);

use App\Enums\SubscriptionEventSource;
use App\Enums\SubscriptionEventType;
use App\Enums\SubscriptionStatus;
use App\Models\AuditLog;
use App\Models\SubscriptionEvent;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\SubscriptionService;
use App\Support\Audit\AuditActions;
use App\Support\Rbac\Role;
use App\Support\Security\SecretRedactor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Phase E0 — every subscription mutation appends exactly one history event
 * (plus plan_changed when the plan moved), atomically with the state change
 * and its audit entry.
 */
function e0Service(): SubscriptionService
{
    return app(SubscriptionService::class);
}

it('records activation from NULL when a plan is assigned to a subscriber without a subscription', function () {
    $admin = userWithRole(Role::SuperAdmin);
    $this->actingAs($admin);
    $plan = billingPlan(attrs: ['slug' => 'basic']);
    $subscriber = billingSubscriber(null);

    $subscription = e0Service()->activateFor($subscriber, $plan);
    $event = SubscriptionEvent::query()->sole();

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($event->event_type)->toBe(SubscriptionEventType::Activated)
        ->and($event->from_status)->toBeNull()
        ->and($event->to_status)->toBe(SubscriptionStatus::Active)
        ->and($event->from_plan_id)->toBeNull()
        ->and($event->to_plan_id)->toBe($plan->id)
        ->and($event->source)->toBe(SubscriptionEventSource::Admin)
        ->and($event->actor_ref)->toBe('user:'.$admin->id)
        ->and($event->subscriber_id)->toBe($subscriber->id)
        ->and($event->baseline_key)->toBeNull()
        ->and($event->effective_at)->not->toBeNull()
        ->and(AuditLog::where('action', AuditActions::SubscriptionTransitioned)->count())->toBe(1)
        ->and(AuditLog::where('action', AuditActions::SubscriptionTransitioned)->first()->metadata['context']['event_type'])->toBe('activated');
});

it('records the onboarding default plan with source onboarding', function () {
    $plan = billingPlan(attrs: ['slug' => 'free', 'is_default' => true, 'trial_days' => 7]);
    settings()->set('billing.default_plan_slug', 'free');
    $subscriber = billingSubscriber(null);

    e0Service()->assignDefaultIfEnabled($subscriber);
    $event = SubscriptionEvent::query()->sole();

    expect($event->event_type)->toBe(SubscriptionEventType::Activated)
        ->and($event->source)->toBe(SubscriptionEventSource::Onboarding)
        ->and($event->from_status)->toBeNull()
        ->and($event->to_status)->toBe(SubscriptionStatus::Trialing)
        ->and($event->to_plan_id)->toBe($plan->id)
        ->and($event->actor_ref)->toBe('console'); // test runner counts as console (no authenticated user)

    e0Service()->assignDefaultIfEnabled($subscriber->fresh());
    expect(SubscriptionEvent::query()->count())->toBe(1); // never a second trial, never a second event
});

it('chains suspend / activate / cancel / extend events with from → to and a plan_changed event when the plan moves', function () {
    $this->actingAs(userWithRole(Role::SuperAdmin));
    $basic = billingPlan(attrs: ['slug' => 'basic']);
    $plus = billingPlan(attrs: ['slug' => 'plus']);
    $subscriber = billingSubscriber($basic);
    $subscription = $subscriber->subscription;

    e0Service()->suspend($subscription, reason: 'non-payment');
    e0Service()->activate($subscription->fresh(), $plus);
    e0Service()->extend($subscription->fresh(), 10);
    e0Service()->cancel($subscription->fresh());

    $events = SubscriptionEvent::query()->orderBy('id')->get();

    expect($events->pluck('event_type')->map->value->all())->toBe(['suspended', 'activated', 'plan_changed', 'extended', 'cancelled'])
        ->and($events[0]->from_status)->toBe(SubscriptionStatus::Active)->and($events[0]->to_status)->toBe(SubscriptionStatus::Suspended)->and($events[0]->reason)->toBe('non-payment')
        ->and($events[1]->from_status)->toBe(SubscriptionStatus::Suspended)->and($events[1]->to_status)->toBe(SubscriptionStatus::Active)
        ->and($events[1]->from_plan_id)->toBe($basic->id)->and($events[1]->to_plan_id)->toBe($plus->id)
        ->and($events[2]->from_plan_id)->toBe($basic->id)->and($events[2]->to_plan_id)->toBe($plus->id)
        ->and($events[3]->metadata['days'])->toBe(10)
        ->and($events[3]->metadata['current_period_end']['from'])->not->toBeNull()
        ->and($events[4]->to_status)->toBe(SubscriptionStatus::Cancelled)
        ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and(AuditLog::where('action', AuditActions::SubscriptionTransitioned)->count())->toBe(5);

    // The chain is consistent: each from_status equals the previous to_status.
    for ($i = 1; $i < $events->count(); $i++) {
        expect($events[$i]->from_status)->toBe($events[$i - 1]->to_status);
    }
});

it('is atomic: when the audit entry cannot be written, neither the state nor the event changes', function () {
    $subscriber = billingSubscriber(billingPlan());
    $subscription = $subscriber->subscription;

    app()->instance(AuditLogger::class, new class(app(SecretRedactor::class)) extends AuditLogger
    {
        public function record(string $action, ?Model $subject = null, array $changes = [], array $context = []): AuditLog
        {
            throw new RuntimeException('audit store unavailable');
        }
    });

    expect(fn () => app(SubscriptionService::class)->suspend($subscription))->toThrow(RuntimeException::class);

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and(SubscriptionEvent::query()->count())->toBe(0);
});

it('never updates an event row (append-only model)', function () {
    $subscriber = billingSubscriber(billingPlan());
    e0Service()->suspend($subscriber->subscription);
    $event = SubscriptionEvent::query()->sole();

    expect(SubscriptionEvent::UPDATED_AT)->toBeNull()
        ->and($event->getAttribute('updated_at'))->toBeNull()
        ->and(Schema::hasColumn('subscription_events', 'updated_at'))->toBeFalse();
});
