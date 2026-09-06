<?php

declare(strict_types=1);

use App\Enums\SubscriptionEventSource;
use App\Enums\SubscriptionEventType;
use App\Enums\SubscriptionStatus;
use App\Exceptions\Billing\StaleSubscriptionStateException;
use App\Models\AuditLog;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\SubscriptionService;
use App\Support\Audit\AuditActions;
use App\Support\Billing\SubscriptionStateToken;
use App\Support\Rbac\Role;
use App\Support\Security\SecretRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

function e0Token(Subscription $subscription): string
{
    return SubscriptionStateToken::for($subscription->fresh());
}

it('records activation from NULL when a plan is assigned to a subscriber without a subscription', function () {
    $admin = userWithRole(Role::SuperAdmin);
    $this->actingAs($admin);
    $plan = billingPlan(attrs: ['slug' => 'basic']);
    $subscriber = billingSubscriber(null);

    $subscription = e0Service()->activateFor($subscriber, $plan, SubscriptionStateToken::NONE);
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
        ->and($event->from_period_start)->toBeNull()->and($event->from_period_end)->toBeNull() // no prior period: nothing invented
        ->and($event->to_period_start->equalTo($subscription->current_period_start))->toBeTrue()
        ->and($event->to_period_end->equalTo($subscription->current_period_end))->toBeTrue()
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

    e0Service()->suspend($subscription, e0Token($subscription), reason: 'non-payment');
    e0Service()->activate($subscription->fresh(), e0Token($subscription), $plus);
    e0Service()->extend($subscription->fresh(), 10, e0Token($subscription));
    e0Service()->cancel($subscription->fresh(), e0Token($subscription));

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

    $token = SubscriptionStateToken::for($subscription);
    expect(fn () => app(SubscriptionService::class)->suspend($subscription, $token))->toThrow(RuntimeException::class);

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and(SubscriptionEvent::query()->count())->toBe(0);
});

it('never updates an event row (append-only model)', function () {
    $subscriber = billingSubscriber(billingPlan());
    e0Service()->suspend($subscriber->subscription, e0Token($subscriber->subscription));
    $event = SubscriptionEvent::query()->sole();

    expect(SubscriptionEvent::UPDATED_AT)->toBeNull()
        ->and($event->getAttribute('updated_at'))->toBeNull()
        ->and(Schema::hasColumn('subscription_events', 'updated_at'))->toBeFalse();
});

it('refuses an admin mutation whose state token is stale: nothing written, no event, no audit', function () {
    $subscriber = billingSubscriber(billingPlan());
    $subscription = $subscriber->subscription;
    $seenByAdminA = SubscriptionStateToken::for($subscription);
    $seenByAdminB = $seenByAdminA; // both admins looked at the same state

    e0Service()->suspend($subscription, $seenByAdminA); // admin A wins

    expect(fn () => e0Service()->activate($subscription->fresh(), $seenByAdminB))->toThrow(StaleSubscriptionStateException::class)
        ->and(fn () => e0Service()->extend($subscription->fresh(), 5, $seenByAdminB))->toThrow(StaleSubscriptionStateException::class)
        ->and(fn () => e0Service()->cancel($subscription->fresh(), 'garbage-token'))->toThrow(StaleSubscriptionStateException::class)
        ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::Suspended) // projection = the winner
        ->and(SubscriptionEvent::query()->count())->toBe(1)
        ->and(AuditLog::where('action', AuditActions::SubscriptionTransitioned)->count())->toBe(1);

    // After a refresh the loser can act on the NEW state.
    e0Service()->activate($subscription->fresh(), e0Token($subscription));
    expect(SubscriptionEvent::query()->count())->toBe(2);

    // Assigning "as if there were no subscription" while one exists is stale too.
    expect(fn () => e0Service()->activateFor($subscriber->fresh(), billingPlan(), SubscriptionStateToken::NONE))->toThrow(StaleSubscriptionStateException::class)
        ->and(SubscriptionEvent::query()->count())->toBe(2);
});

it('the token changes after every committed transition, even when the projection looks the same', function () {
    $subscriber = billingSubscriber(billingPlan());
    $subscription = $subscriber->subscription;
    $t0 = SubscriptionStateToken::for($subscription);

    e0Service()->extend($subscription, 0, $t0); // same status, same plan
    $t1 = SubscriptionStateToken::for($subscription->fresh());

    expect($t1)->not->toBe($t0)
        ->and(fn () => e0Service()->suspend($subscription->fresh(), $t0))->toThrow(StaleSubscriptionStateException::class);
});

it('snapshots the service period on extend (old/new period end) and leaves no event when the transaction rolls back', function () {
    $this->actingAs(userWithRole(Role::SuperAdmin));
    $plan = billingPlan();
    $start = CarbonImmutable::parse('2026-09-01 00:00:00', 'UTC');
    $end = CarbonImmutable::parse('2026-10-01 00:00:00', 'UTC');
    $subscriber = billingSubscriber($plan, ['current_period_start' => $start, 'current_period_end' => $end]);
    $subscription = $subscriber->subscription;

    e0Service()->extend($subscription, 10, e0Token($subscription));
    $event = SubscriptionEvent::query()->sole();

    expect($event->event_type)->toBe(SubscriptionEventType::Extended)
        ->and($event->from_period_start->equalTo($start))->toBeTrue()
        ->and($event->from_period_end->equalTo($end))->toBeTrue()
        ->and($event->to_period_start->equalTo($start))->toBeTrue()
        ->and($event->to_period_end->equalTo($end->addDays(10)))->toBeTrue()
        ->and($subscription->fresh()->current_period_end->equalTo($end->addDays(10)))->toBeTrue()
        ->and($event->metadata['current_period_end'])->toBe(['from' => $end->toIso8601String(), 'to' => $end->addDays(10)->toIso8601String()]);

    // Rollback path: the audit store fails on the second extend → period and events untouched.
    app()->instance(AuditLogger::class, new class(app(SecretRedactor::class)) extends AuditLogger
    {
        public function record(string $action, ?Model $subject = null, array $changes = [], array $context = []): AuditLog
        {
            throw new RuntimeException('audit store unavailable');
        }
    });

    $token = e0Token($subscription);
    expect(fn () => app(SubscriptionService::class)->extend($subscription->fresh(), 30, $token))->toThrow(RuntimeException::class)
        ->and($subscription->fresh()->current_period_end->equalTo($end->addDays(10)))->toBeTrue()
        ->and(SubscriptionEvent::query()->count())->toBe(1);
});

it('records an explicit, enum-constrained event type rather than inferring it from from/to', function () {
    expect(array_map(fn (SubscriptionEventType $t) => $t->value, SubscriptionEventType::cases()))
        ->toBe(['baseline', 'activated', 'suspended', 'cancelled', 'extended', 'plan_changed', 'status_changed']);

    $subscriber = billingSubscriber(billingPlan());
    e0Service()->extend($subscriber->subscription, 0, e0Token($subscriber->subscription)); // from == to on status/plan/period…
    $event = SubscriptionEvent::query()->sole();

    expect($event->event_type)->toBeInstanceOf(SubscriptionEventType::class)
        ->and($event->event_type)->toBe(SubscriptionEventType::Extended) // …yet the type is explicit
        ->and($event->getRawOriginal('event_type'))->toBe('extended');

    if (DB::connection()->getDriverName() === 'pgsql') {
        // The database itself rejects a type outside the vocabulary (savepoint keeps the test transaction usable).
        expect(fn () => DB::transaction(fn () => DB::table('subscription_events')->insert([
            'subscription_id' => $subscriber->subscription->id, 'subscriber_id' => $subscriber->id, 'event_type' => 'made_up',
            'to_status' => 'active', 'effective_at' => now(), 'source' => 'admin', 'actor_ref' => 'console', 'created_at' => now(),
        ])))->toThrow(QueryException::class);
    }
});
