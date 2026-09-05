<?php

declare(strict_types=1);

use App\Enums\SubscriptionStatus;
use App\Enums\UsageDimension;
use App\Livewire\Dashboard\Plans;
use App\Livewire\Dashboard\SubscriberDetail;
use App\Livewire\Dashboard\Subscribers;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\UsageEngine;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// ---- access control ------------------------------------------------------

$billingRoutes = ['dashboard.plans', 'dashboard.subscribers'];

it('redirects guests from billing admin pages to login', function (string $route) {
    $this->get(route($route))->assertRedirect(route('login'));
})->with($billingRoutes);

it('forbids non-admins from billing admin pages', function (string $route) {
    $this->actingAs(User::factory()->create(['is_admin' => false]))->get(route($route))->assertForbidden();
})->with($billingRoutes);

// ---- plan management -----------------------------------------------------

it('lets an admin create a plan with configurable limits', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(Plans::class)
        ->call('new')
        ->set('name', 'باقة بلس')
        ->set('slug', 'plus-test')
        ->set('price', '49.00')
        ->set('currency', 'ILS')
        ->set('billing_period', 'monthly')
        ->set('trial_days', 7)
        ->set('ai_daily', 100)
        ->set('ai_monthly', 2000)
        ->call('save')
        ->assertHasNoErrors();

    $plan = Plan::where('slug', 'plus-test')->first();
    expect($plan)->not->toBeNull()
        ->and($plan->limitFor(UsageDimension::AiReply))->toMatchArray(['daily' => 100, 'monthly' => 2000]);
});

it('enforces a single default plan', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $old = billingPlan([], ['slug' => 'old-default', 'is_default' => true]);

    Livewire::actingAs($admin)
        ->test(Plans::class)
        ->call('new')
        ->set('name', 'New Default')
        ->set('slug', 'new-default')
        ->set('price', '0')
        ->set('currency', 'ILS')
        ->set('is_default', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($old->refresh()->is_default)->toBeFalse()
        ->and(Plan::where('slug', 'new-default')->first()->is_default)->toBeTrue();
});

it('lets an admin toggle a plan active/inactive', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $plan = billingPlan([], ['is_active' => true]);

    Livewire::actingAs($admin)->test(Plans::class)->call('toggleActive', $plan->id);

    expect($plan->refresh()->is_active)->toBeFalse();
});

// ---- subscriber admin ----------------------------------------------------

it('shows a subscriber with plan, status and AI usage', function () {
    config(['billing.enforce' => true]);
    $admin = User::factory()->create(['is_admin' => true]);
    $plan = billingPlan(['daily' => 5, 'monthly' => 50]);
    $subscriber = billingSubscriber($plan);
    app(UsageEngine::class)->charge($subscriber, UsageDimension::AiReply, 'seed');

    Livewire::actingAs($admin)
        ->test(SubscriberDetail::class, ['subscriber' => $subscriber])
        ->assertOk()
        ->assertViewHas('used', fn (array $used) => $used['daily'] === 1)
        ->assertSee($plan->name);
});

it('lets an admin assign a plan and suspend a subscriber', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $plan = billingPlan([], ['slug' => 'assignable']);
    $subscriber = billingSubscriber(null); // no subscription yet

    $component = Livewire::actingAs($admin)
        ->test(SubscriberDetail::class, ['subscriber' => $subscriber])
        ->set('planId', $plan->id)
        ->call('assignPlan');

    expect($subscriber->refresh()->subscription->status)->toBe(SubscriptionStatus::Active);

    $component->call('suspend');
    expect($subscriber->refresh()->subscription->status)->toBe(SubscriptionStatus::Suspended);
});

it('lets an admin list subscribers', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    billingSubscriber(billingPlan(), []);

    Livewire::actingAs($admin)->test(Subscribers::class)->assertOk();
});

it('seeds plans priced in USD', function () {
    $this->seed(PlanSeeder::class);

    expect(Plan::query()->pluck('currency')->unique()->all())->toBe(['USD'])
        ->and(config('billing.currency'))->toBe('USD');
});

it('defaults a new plan form to the billing currency (USD)', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(Plans::class)
        ->call('new')
        ->assertSet('currency', 'USD');
});
