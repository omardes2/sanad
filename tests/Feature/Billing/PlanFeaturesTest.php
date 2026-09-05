<?php

declare(strict_types=1);

use App\Enums\PlanFeature;
use App\Enums\SubscriptionStatus;
use App\Enums\UsageDimension;
use App\Services\Billing\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reads boolean and tiered features with sensible defaults', function () {
    $plan = billingPlan([], [
        'features' => [
            PlanFeature::Tools->value => true,
            PlanFeature::Priority->value => 'high',
        ],
    ]);

    expect($plan->hasFeature(PlanFeature::Tools))->toBeTrue()
        // Absent feature falls back to its own default — no data backfill needed.
        ->and($plan->hasFeature(PlanFeature::Images))->toBeFalse()
        ->and($plan->featureValue(PlanFeature::Priority))->toBe('high')
        ->and($plan->featureValue(PlanFeature::Memory))->toBeFalse();
});

it('treats the lowest tier as not-entitled for hasFeature', function () {
    $plan = billingPlan([], ['features' => [PlanFeature::Priority->value => 'normal']]);

    // 'normal' is the lowest priority tier → not a raised entitlement.
    expect($plan->hasFeature(PlanFeature::Priority))->toBeFalse()
        ->and($plan->featureValue(PlanFeature::Priority))->toBe('normal');
});

it('gates features through the subscription service on the current plan', function () {
    $plan = billingPlan([], ['features' => [PlanFeature::Voice->value => true]]);
    $subscriber = billingSubscriber($plan);

    $service = app(SubscriptionService::class);

    expect($service->hasFeature($subscriber, PlanFeature::Voice))->toBeTrue()
        ->and($service->hasFeature($subscriber, PlanFeature::Tools))->toBeFalse();
});

it('grants no features to a subscriber without an entitled plan', function () {
    $plan = billingPlan([], ['features' => [PlanFeature::Voice->value => true]]);
    $subscriber = billingSubscriber($plan, ['status' => SubscriptionStatus::Suspended]);

    $service = app(SubscriptionService::class);

    expect($service->hasFeature($subscriber, PlanFeature::Voice))->toBeFalse()
        ->and($service->featureValue($subscriber, PlanFeature::Priority))->toBe(PlanFeature::Priority->default());
});

it('supports independent limits per dimension with unlimited and absent windows', function () {
    $plan = billingPlan([], [
        'limits' => [
            UsageDimension::AiReply->value => ['daily' => 100, 'monthly' => null, 'weight' => 1],
            UsageDimension::Reminder->value => ['daily' => 10, 'monthly' => 100, 'weight' => 1],
        ],
    ]);

    expect($plan->limitFor(UsageDimension::AiReply))->toMatchArray(['daily' => 100, 'monthly' => null])
        ->and($plan->limitFor(UsageDimension::Reminder))->toMatchArray(['daily' => 10, 'monthly' => 100])
        // A dimension not present in limits is simply not entitled.
        ->and($plan->limitFor(UsageDimension::Image))->toBeNull();
});

it('exposes every feature to the enum-driven admin editor', function () {
    // The admin editor iterates PlanFeature::cases(); this guards that the
    // product feature set the brief asked for is present and enum-backed.
    $keys = array_map(fn (PlanFeature $f) => $f->value, PlanFeature::cases());

    expect($keys)->toContain('expense_tracking', 'memory', 'advanced_memory', 'tools', 'priority', 'voice', 'images', 'reminders', 'tasks', 'calls');
});
