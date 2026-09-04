<?php

declare(strict_types=1);

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    whatsappConfigure();
    Queue::fake();
    config(['billing.auto_trial' => true, 'billing.default_plan_slug' => 'free']);
});

it('auto-assigns the default trial plan when a new WhatsApp subscriber is onboarded', function () {
    billingPlan(['daily' => 5, 'monthly' => 50], ['slug' => 'free', 'trial_days' => 3, 'is_default' => true]);

    runWhatsAppWebhook(whatsappTextEnvelope('wamid.s1', '970599700001', 'مرحبا'));

    $subscriber = User::query()->where('phone', '+970599700001')->first();
    $subscription = $subscriber->subscription;

    expect($subscription)->not->toBeNull()
        ->and($subscription->status)->toBe(SubscriptionStatus::Trialing)
        ->and($subscription->plan->slug)->toBe('free')
        ->and($subscription->trial_ends_at->isFuture())->toBeTrue();
});

it('never grants a second trial on repeated onboarding', function () {
    billingPlan(['daily' => 5, 'monthly' => 50], ['slug' => 'free', 'trial_days' => 3, 'is_default' => true]);

    runWhatsAppWebhook(whatsappTextEnvelope('wamid.s1', '970599700002', 'مرحبا'));
    runWhatsAppWebhook(whatsappTextEnvelope('wamid.s2', '970599700002', 'مرة ثانية'));

    expect(Subscription::count())->toBe(1);
});

it('does not assign any plan when auto_trial is disabled', function () {
    config(['billing.auto_trial' => false]);
    billingPlan(['daily' => 5, 'monthly' => 50], ['slug' => 'free', 'trial_days' => 3, 'is_default' => true]);

    runWhatsAppWebhook(whatsappTextEnvelope('wamid.s3', '970599700003', 'مرحبا'));

    expect(Subscription::count())->toBe(0);
});

it('assigns an active (non-trial) subscription when the default plan has no trial days', function () {
    $plan = billingPlan(['daily' => 5, 'monthly' => 50], ['slug' => 'free', 'trial_days' => 0, 'is_default' => true]);
    $subscriber = User::factory()->create(['is_admin' => false]);

    app(SubscriptionService::class)->assignDefaultIfEnabled($subscriber);

    expect($subscriber->subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscriber->subscription->plan_id)->toBe($plan->id)
        ->and($subscriber->subscription->trial_ends_at)->toBeNull();
});

it('returns the existing subscription without creating a duplicate', function () {
    billingPlan(['daily' => 5, 'monthly' => 50], ['slug' => 'free', 'is_default' => true]);
    $subscriber = User::factory()->create(['is_admin' => false]);
    $service = app(SubscriptionService::class);

    $first = $service->assignDefaultIfEnabled($subscriber);
    $second = $service->assignDefaultIfEnabled($subscriber->refresh());

    expect($second->id)->toBe($first->id)
        ->and(Subscription::count())->toBe(1);
});
