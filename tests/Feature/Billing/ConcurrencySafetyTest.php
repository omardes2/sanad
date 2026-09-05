<?php

declare(strict_types=1);

use App\Agents\AiAgentOrchestrator;
use App\Agents\MeteredAgentOrchestrator;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\UsageDimension;
use App\Enums\UsageOutcome;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\UsageEvent;
use App\Services\Billing\UsageEngine;
use App\Services\Billing\UsageLimitResponder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['billing.enforce' => true]);
});

it('does not charge a duplicate message twice (two messages arriving together, same key)', function () {
    $subscriber = billingSubscriber(billingPlan(['daily' => 5, 'monthly' => 50]));
    $engine = app(UsageEngine::class);

    // Same inbound message delivered twice (duplicate webhook) → identical key.
    $first = $engine->charge($subscriber, UsageDimension::AiReply, 'ai_reply:777');
    $second = $engine->charge($subscriber, UsageDimension::AiReply, 'ai_reply:777');

    expect($first->outcome)->toBe(UsageOutcome::Allowed)
        ->and($second->outcome)->toBe(UsageOutcome::AlreadyCharged)
        ->and($engine->usage($subscriber, UsageDimension::AiReply)['daily'])->toBe(1) // charged once
        ->and(UsageEvent::where('idempotency_key', 'ai_reply:777')->count())->toBe(1);
});

it('charges only one of two distinct messages at the daily boundary (cap not exceeded)', function () {
    $subscriber = billingSubscriber(billingPlan(['daily' => 1, 'monthly' => 50]));
    $engine = app(UsageEngine::class);

    $a = $engine->charge($subscriber, UsageDimension::AiReply, 'ai_reply:1');
    $b = $engine->charge($subscriber, UsageDimension::AiReply, 'ai_reply:2');

    expect($a->outcome)->toBe(UsageOutcome::Allowed)
        ->and($b->outcome)->toBe(UsageOutcome::LimitReached)
        ->and($engine->usage($subscriber, UsageDimension::AiReply)['daily'])->toBe(1) // never 2
        ->and(UsageEvent::count())->toBe(1);
});

it('a burst of many messages never pushes the counter past the daily cap', function () {
    $subscriber = billingSubscriber(billingPlan(['daily' => 3, 'monthly' => 100]));
    $engine = app(UsageEngine::class);

    $allowed = 0;
    foreach (range(1, 12) as $i) {
        if ($engine->charge($subscriber, UsageDimension::AiReply, "ai_reply:burst-{$i}")->outcome === UsageOutcome::Allowed) {
            $allowed++;
        }
    }

    expect($allowed)->toBe(3)
        ->and($engine->usage($subscriber, UsageDimension::AiReply)['daily'])->toBe(3)
        ->and(UsageEvent::count())->toBe(3);
});

it('through the orchestrator: a duplicated inbound message is charged only once', function () {
    aiConfigure();
    config(['billing.enforce' => true]);
    Http::fake(['api.groq.com/*' => Http::response([
        'model' => 'llama-3.3-70b-versatile',
        'choices' => [['message' => ['content' => 'رد'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2],
    ], 200)]);

    $subscriber = billingSubscriber(billingPlan(['daily' => 5, 'monthly' => 50]));
    $account = ChannelAccount::factory()->for($subscriber)->create();
    $conversation = Conversation::factory()->for($subscriber)->create(['channel_account_id' => $account->id]);
    $message = Message::factory()->for($subscriber)->for($conversation)->create([
        'direction' => MessageDirection::Inbound,
        'type' => MessageType::Text,
        'text_content' => 'مرحبا',
    ]);

    $metered = new MeteredAgentOrchestrator(
        app(AiAgentOrchestrator::class),
        app(UsageEngine::class),
        app(UsageLimitResponder::class),
    );

    // Same inbound message handled twice (idempotency key = ai_reply:{message id}).
    $metered->handle($subscriber, $conversation, $message);
    $metered->handle($subscriber, $conversation, $message);

    expect(app(UsageEngine::class)->usage($subscriber, UsageDimension::AiReply)['daily'])->toBe(1)
        ->and(UsageEvent::where('idempotency_key', 'ai_reply:'.$message->id)->count())->toBe(1);
});
