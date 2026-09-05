<?php

declare(strict_types=1);

use App\Enums\ChannelType;
use App\Enums\UsageDimension;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\UsageEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    whatsappConfigure();
    Queue::fake();
    config([
        'billing.auto_trial' => true,
        'billing.default_plan_slug' => 'free',
    ]);
    billingPlan(['daily' => 5, 'monthly' => 50], ['slug' => 'free', 'trial_days' => 3, 'is_default' => true]);
});

it('keeps the dashboard admin completely separate from WhatsApp subscribers', function () {
    // An operator/admin exists (dashboard login account).
    $admin = User::factory()->create(['is_admin' => true, 'email' => 'admin@sanad.local']);

    // Two different WhatsApp numbers message in.
    runWhatsAppWebhook(whatsappTextEnvelope('wamid.iso1', '970599800001', 'مرحبا'));
    runWhatsAppWebhook(whatsappTextEnvelope('wamid.iso2', '970599800002', 'مرحبا'));

    $subA = User::query()->where('phone', '+970599800001')->first();
    $subB = User::query()->where('phone', '+970599800002')->first();

    // Each number is its own non-admin subscriber, distinct from the admin.
    expect($subA)->not->toBeNull()
        ->and($subB)->not->toBeNull()
        ->and($subA->id)->not->toBe($admin->id)
        ->and($subB->id)->not->toBe($admin->id)
        ->and($subA->id)->not->toBe($subB->id)
        ->and($subA->is_admin)->toBeFalse()
        ->and($subB->is_admin)->toBeFalse();

    // The admin owns NO WhatsApp channel account and NO subscription.
    expect($admin->channelAccounts()->count())->toBe(0)
        ->and($admin->subscription()->exists())->toBeFalse();

    // Each subscriber owns their own channel account + independent subscription.
    expect($subA->channelAccounts()->where('channel', ChannelType::WhatsApp)->count())->toBe(1)
        ->and($subB->channelAccounts()->where('channel', ChannelType::WhatsApp)->count())->toBe(1)
        ->and($subA->subscription)->not->toBeNull()
        ->and($subB->subscription)->not->toBeNull()
        ->and($subA->subscription->id)->not->toBe($subB->subscription->id);

    // Exactly two subscriptions total (one per number) — the admin has none.
    expect(Subscription::count())->toBe(2);
});

it('gives each new WhatsApp number an independent usage balance', function () {
    config(['billing.enforce' => true]);

    runWhatsAppWebhook(whatsappTextEnvelope('wamid.iso3', '970599800003', 'مرحبا'));
    runWhatsAppWebhook(whatsappTextEnvelope('wamid.iso4', '970599800004', 'مرحبا'));

    $a = User::query()->where('phone', '+970599800003')->first();
    $b = User::query()->where('phone', '+970599800004')->first();

    $engine = app(UsageEngine::class);
    $engine->charge($a, UsageDimension::AiReply, 'iso-a-1');
    $engine->charge($a, UsageDimension::AiReply, 'iso-a-2');

    // A's usage does not affect B's independent balance.
    expect($engine->usage($a, UsageDimension::AiReply)['daily'])->toBe(2)
        ->and($engine->usage($b, UsageDimension::AiReply)['daily'])->toBe(0);
});

it('never links a WhatsApp subscriber to the admin even when an admin exists first', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    runWhatsAppWebhook(whatsappTextEnvelope('wamid.iso5', '970599800005', 'مرحبا'));

    $subscriber = User::query()->where('phone', '+970599800005')->first();

    expect($subscriber->id)->not->toBe($admin->id)
        ->and($subscriber->subscription->subscriber_id)->toBe($subscriber->id)
        ->and($subscriber->subscription->subscriber_id)->not->toBe($admin->id);
});
