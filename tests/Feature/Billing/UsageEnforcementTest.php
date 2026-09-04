<?php

declare(strict_types=1);

use App\Agents\AiAgentOrchestrator;
use App\Agents\MeteredAgentOrchestrator;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\UsageDimension;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Billing\UsageEngine;
use App\Services\Billing\UsageLimitResponder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    aiConfigure();
    config(['billing.enforce' => true]);
});

function metered(): MeteredAgentOrchestrator
{
    return new MeteredAgentOrchestrator(
        app(AiAgentOrchestrator::class),
        app(UsageEngine::class),
        app(UsageLimitResponder::class),
    );
}

function meteredMessage(User $user, string $text = 'مرحبا'): array
{
    $account = ChannelAccount::factory()->for($user)->create();
    $conversation = Conversation::factory()->for($user)->create(['channel_account_id' => $account->id]);
    $message = Message::factory()->for($user)->for($conversation)->create([
        'direction' => MessageDirection::Inbound,
        'type' => MessageType::Text,
        'text_content' => $text,
    ]);

    return [$conversation, $message];
}

function fakeGroqOk(string $content = 'رد الذكاء', int $status = 200): void
{
    Http::fake(['api.groq.com/*' => Http::response([
        'model' => 'llama-3.3-70b-versatile',
        'choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 8, 'completion_tokens' => 3],
    ], $status)]);
}

it('does NOT call the AI provider when the subscriber is over the limit', function () {
    $subscriber = billingSubscriber(billingPlan(['daily' => 1, 'monthly' => 50]));
    app(UsageEngine::class)->charge($subscriber, UsageDimension::AiReply, 'seed'); // fill the day
    fakeGroqOk();
    [$conversation, $message] = meteredMessage($subscriber);

    $reply = metered()->handle($subscriber, $conversation, $message);

    Http::assertNothingSent(); // provider never called
    expect($reply->text)->toContain('وصلت إلى الحدّ')
        ->and($reply->metadata['usage']['denied'])->toBe('limit_reached');
});

it('calls the AI provider and charges exactly once when allowed', function () {
    $subscriber = billingSubscriber(billingPlan(['daily' => 5, 'monthly' => 50]));
    fakeGroqOk('أهلًا وسهلًا');
    [$conversation, $message] = meteredMessage($subscriber);

    $reply = metered()->handle($subscriber, $conversation, $message);

    Http::assertSentCount(1);
    expect($reply->text)->toBe('أهلًا وسهلًا')
        ->and(app(UsageEngine::class)->usage($subscriber, UsageDimension::AiReply)['daily'])->toBe(1);
});

it('does not charge when the AI provider fails', function () {
    config(['ai.failure_behavior' => 'reply']);
    $subscriber = billingSubscriber(billingPlan(['daily' => 5, 'monthly' => 50]));
    fakeGroqOk('', 500); // provider failure → AI fallback
    [$conversation, $message] = meteredMessage($subscriber);

    $reply = metered()->handle($subscriber, $conversation, $message);

    expect($reply->text)->toBe(config('ai.fallback_message'))
        ->and(app(UsageEngine::class)->usage($subscriber, UsageDimension::AiReply)['daily'])->toBe(0);
});

it('returns a disabled message and skips AI when there is no entitlement', function () {
    $subscriber = billingSubscriber(null); // no subscription
    fakeGroqOk();
    [$conversation, $message] = meteredMessage($subscriber);

    $reply = metered()->handle($subscriber, $conversation, $message);

    Http::assertNothingSent();
    expect($reply->metadata['usage']['denied'])->toBe('disabled');
});

it('sends a real AI reply once, then the limit message on the next message', function () {
    $subscriber = billingSubscriber(billingPlan(['daily' => 1, 'monthly' => 50]));
    fakeGroqOk('الرد الأول');

    [$c1, $m1] = meteredMessage($subscriber, 'سؤال ١');
    $first = metered()->handle($subscriber, $c1, $m1);

    [$c2, $m2] = meteredMessage($subscriber, 'سؤال ٢');
    $second = metered()->handle($subscriber, $c2, $m2);

    Http::assertSentCount(1); // AI called only for the first message
    expect($first->text)->toBe('الرد الأول')
        ->and($second->text)->toContain('وصلت إلى الحدّ');
});
