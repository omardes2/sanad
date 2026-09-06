<?php

declare(strict_types=1);

use App\Data\Billing\UsageRecord;
use App\Enums\UsageDimension;
use App\Enums\UsageEventOutcome;
use App\Models\Subscription;
use App\Models\UsageCharge;
use App\Models\UsageCounter;
use App\Models\UsageEvent;
use App\Models\User;
use App\Services\Billing\SubscriptionService;
use App\Services\Billing\UsageRecorder;
use App\Support\Billing\SubscriptionStateToken;
use App\Support\Billing\UsageKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function recorder(): UsageRecorder
{
    return app(UsageRecorder::class);
}

function usageRecord(array $overrides = []): UsageRecord
{
    $defaults = [
        // Only build a default subscriber when the caller did not supply one
        // (each call gets its own randomly-slugged plan).
        'subscriber' => $overrides['subscriber'] ?? billingSubscriber(billingPlan(['daily' => 5, 'monthly' => 50])),
        'dimension' => UsageDimension::AiReply,
        'idempotencyKey' => 'ai_reply:message:1#1',
        'correlationId' => 'message:1',
        'operation' => 'chat',
        'provider' => 'openai',
        'model' => 'gpt-4.1-mini',
        'channel' => 'whatsapp',
        'inputUnits' => 100,
        'outputUnits' => 40,
        'cachedUnits' => 10,
        'durationMs' => 850,
    ];

    return new UsageRecord(...array_merge($defaults, $overrides));
}

it('records a ledger row while enforcement is OFF and touches neither counters nor charges', function () {
    config(['billing.enforce' => false]);

    $result = recorder()->record(usageRecord());
    $event = $result->event;

    expect($result->created)->toBeTrue()
        ->and($event->type)->toBe('ai_reply')
        ->and($event->operation)->toBe('chat')
        ->and($event->provider)->toBe('openai')
        ->and($event->model)->toBe('gpt-4.1-mini')
        ->and($event->channel)->toBe('whatsapp')
        ->and($event->input_units)->toBe(100)
        ->and($event->output_units)->toBe(40)
        ->and($event->cached_units)->toBe(10)
        ->and($event->duration_ms)->toBe(850)
        ->and($event->outcome)->toBe(UsageEventOutcome::Succeeded)
        ->and($event->correlation_id)->toBe('message:1')
        ->and($event->occurred_at)->not->toBeNull()
        ->and(UsageCounter::count())->toBe(0)
        ->and(UsageCharge::count())->toBe(0);
});

it('is idempotent: the same invocation key is recorded exactly once and replays report created=false', function () {
    $record = usageRecord();

    $first = recorder()->record($record);
    $second = recorder()->record($record);

    expect($first->created)->toBeTrue()
        ->and($second->created)->toBeFalse()
        ->and($second->event->id)->toBe($first->event->id)
        ->and(UsageEvent::count())->toBe(1);
});

it('allows several legitimate billable invocations for one logical request (same correlation, different keys)', function () {
    $subscriber = billingSubscriber(billingPlan());
    $correlation = UsageKeys::correlationForMessage(42);

    recorder()->record(usageRecord(['subscriber' => $subscriber, 'correlationId' => $correlation,
        'idempotencyKey' => UsageKeys::invocation(UsageDimension::AiReply, $correlation, 1)]));
    // e.g. a second AI round after a tool result, or a fallback provider call
    recorder()->record(usageRecord(['subscriber' => $subscriber, 'correlationId' => $correlation,
        'idempotencyKey' => UsageKeys::invocation(UsageDimension::AiReply, $correlation, 2), 'provider' => 'groq']));

    expect(UsageEvent::where('correlation_id', $correlation)->count())->toBe(2)
        ->and(UsageKeys::invocation(UsageDimension::AiReply, $correlation, 2))->toBe('ai_reply:message:42#2');
});

it('snapshots the subscription and plan in force at record time — history survives upgrades and deletion', function () {
    $plus = billingPlan([], ['slug' => 'plus-h']);
    $pro = billingPlan([], ['slug' => 'pro-h']);
    $subscriber = billingSubscriber($plus);
    $subscriptionId = $subscriber->subscription->id;

    $onPlus = recorder()->record(usageRecord(['subscriber' => $subscriber, 'idempotencyKey' => 'h#1']))->event;

    // Upgrade mutates plan_id on the SAME subscription row.
    app(SubscriptionService::class)->activate($subscriber->subscription, SubscriptionStateToken::for($subscriber->subscription), $pro);
    $onPro = recorder()->record(usageRecord(['subscriber' => $subscriber->fresh(), 'idempotencyKey' => 'h#2']))->event;

    expect($onPlus->subscription_id)->toBe($subscriptionId)
        ->and($onPlus->plan_id)->toBe($plus->id)
        ->and($onPlus->plan_slug)->toBe('plus-h')
        ->and($onPro->subscription_id)->toBe($subscriptionId)
        ->and($onPro->plan_id)->toBe($pro->id)
        ->and($onPro->plan_slug)->toBe('pro-h');

    // Hard-deleting the subscription (e.g. cascaded with the user) leaves the ledger intact.
    Subscription::whereKey($subscriptionId)->delete();

    expect($onPlus->fresh()->subscription_id)->toBe($subscriptionId)
        ->and($onPlus->fresh()->plan_slug)->toBe('plus-h');
});

it('still records for a subscriber without any subscription (cost is real regardless)', function () {
    $event = recorder()->record(usageRecord(['subscriber' => billingSubscriber(null)]))->event;

    expect($event->subscription_id)->toBeNull()
        ->and($event->plan_id)->toBeNull()
        ->and($event->total_cost)->not->toBeNull();
});

it('stores computed costs immutably on the row with `cost` mirroring total_cost', function () {
    config(['billing.cost_rates.ai_reply' => ['unit' => 'event', 'rate' => 0.25], 'billing.cost_currency' => 'USD']);

    $event = recorder()->record(usageRecord())->event;

    expect((float) $event->provider_cost)->toBe(0.25)
        ->and((float) $event->communication_cost)->toBe(0.0)
        ->and((float) $event->external_cost)->toBe(0.0)
        ->and((float) $event->total_cost)->toBe(0.25)
        ->and((float) $event->cost)->toBe(0.25)
        ->and($event->currency)->toBe('USD');

    // Changing the rate afterwards must not rewrite history.
    config(['billing.cost_rates.ai_reply' => ['unit' => 'event', 'rate' => 9.99]]);
    expect((float) $event->fresh()->total_cost)->toBe(0.25);
});

it('can record billable consumption whose downstream stage failed', function () {
    $event = recorder()->record(usageRecord(['outcome' => UsageEventOutcome::DownstreamFailed]))->event;

    expect($event->outcome)->toBe(UsageEventOutcome::DownstreamFailed);
});

it('keeps historical attribution to the subscriber after the user is hard-deleted', function () {
    $subscriber = billingSubscriber(billingPlan());
    $ownerId = $subscriber->id;

    $event = recorder()->record(usageRecord(['subscriber' => $subscriber]))->event;
    expect($event->user_id)->toBe($ownerId)->and($event->subscriber_id)->toBe($ownerId);

    User::whereKey($ownerId)->delete(); // existing policy: usage events retained, user_id nulled

    $event = $event->fresh();
    expect($event)->not->toBeNull()
        ->and($event->user_id)->toBeNull()
        ->and($event->subscriber_id)->toBe($ownerId); // pseudonymous owner never lost
});

it('attributes WhatsApp dimensions to communication cost, not provider cost', function () {
    config(['billing.cost_rates.whatsapp_outbound' => ['unit' => 'event', 'rate' => 0.05]]);

    $event = recorder()->record(usageRecord([
        'dimension' => UsageDimension::WhatsAppOutbound,
        'idempotencyKey' => 'whatsapp_outbound:message:9#1',
    ]))->event;

    expect((float) $event->communication_cost)->toBe(0.05)
        ->and((float) $event->provider_cost)->toBe(0.0)
        ->and((float) $event->total_cost)->toBe(0.05);
});

it('derives invocation identity deterministically — never from existing rows', function () {
    $correlation = UsageKeys::correlationForMessage(7);
    $key = UsageKeys::invocation(UsageDimension::AiReply, $correlation);

    // Pure function of its inputs: identical before and after rows exist,
    // so concurrent workers handling the same invocation compute the same key.
    expect($key)->toBe('ai_reply:message:7#1');
    recorder()->record(usageRecord(['correlationId' => $correlation, 'idempotencyKey' => $key]));
    recorder()->record(usageRecord(['correlationId' => $correlation, 'idempotencyKey' => $key]));

    expect(UsageKeys::invocation(UsageDimension::AiReply, $correlation))->toBe($key)
        ->and(UsageEvent::where('correlation_id', $correlation)->count())->toBe(1)
        // A genuinely new invocation is a different but equally stable key.
        ->and(UsageKeys::invocation(UsageDimension::AiReply, $correlation, 2))->toBe('ai_reply:message:7#2');
});
