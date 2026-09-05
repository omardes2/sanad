<?php

declare(strict_types=1);

use App\Agents\AiAgentOrchestrator;
use App\Agents\MeteredAgentOrchestrator;
use App\Contracts\AgentOrchestrator;
use App\Data\AgentResponseData;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\UsageDimension;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\UsageCharge;
use App\Models\UsageCounter;
use App\Models\UsageEvent;
use App\Models\User;
use App\Services\Billing\UsageEngine;
use App\Services\Billing\UsageLimitResponder;
use App\Services\Billing\UsageRecorder;
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
        app(UsageRecorder::class),
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
        'usage' => ['prompt_tokens' => 8, 'completion_tokens' => 3, 'prompt_tokens_details' => ['cached_tokens' => 2]],
    ], $status)]);
}

// ---- enforcement (billing.enforce = true) --------------------------------

it('does NOT call the AI provider when the subscriber is over the limit', function () {
    $subscriber = billingSubscriber(billingPlan(['daily' => 1, 'monthly' => 50]));
    app(UsageEngine::class)->charge($subscriber, UsageDimension::AiReply, 'seed'); // fill the day
    fakeGroqOk();
    [$conversation, $message] = meteredMessage($subscriber);

    $reply = metered()->handle($subscriber, $conversation, $message);

    Http::assertNothingSent(); // provider never called
    expect($reply->text)->toContain('وصلت إلى الحدّ')
        ->and($reply->metadata['usage']['denied'])->toBe('limit_reached')
        // Nothing billable was consumed → nothing recorded.
        ->and(UsageEvent::count())->toBe(0);
});

it('calls the AI provider, records the ledger row and consumes quota exactly once when allowed', function () {
    $subscriber = billingSubscriber(billingPlan(['daily' => 5, 'monthly' => 50]));
    fakeGroqOk('أهلًا وسهلًا');
    [$conversation, $message] = meteredMessage($subscriber);

    $reply = metered()->handle($subscriber, $conversation, $message);

    Http::assertSentCount(1);
    $event = UsageEvent::sole();

    expect($reply->text)->toBe('أهلًا وسهلًا')
        ->and(app(UsageEngine::class)->usage($subscriber, UsageDimension::AiReply)['daily'])->toBe(1)
        ->and(UsageCharge::count())->toBe(1)
        ->and($event->type)->toBe('ai_reply')
        ->and($event->operation)->toBe('chat')
        ->and($event->provider)->toBe('groq')
        ->and($event->model)->toBe('llama-3.3-70b-versatile')
        ->and($event->input_units)->toBe(8)
        ->and($event->output_units)->toBe(3)
        ->and($event->cached_units)->toBe(2)
        ->and($event->correlation_id)->toBe('message:'.$message->id)
        ->and($event->subscription_id)->toBe($subscriber->subscription->id)
        ->and($event->plan_id)->toBe($subscriber->subscription->plan_id)
        ->and($event->occurred_at)->not->toBeNull();
});

it('records nothing and charges nothing when the AI provider fails (nothing billable consumed)', function () {
    config(['ai.failure_behavior' => 'reply']);
    $subscriber = billingSubscriber(billingPlan(['daily' => 5, 'monthly' => 50]));
    fakeGroqOk('', 500); // provider failure → AI fallback
    [$conversation, $message] = meteredMessage($subscriber);

    $reply = metered()->handle($subscriber, $conversation, $message);

    expect($reply->text)->toBe(config('ai.fallback_message'))
        ->and(app(UsageEngine::class)->usage($subscriber, UsageDimension::AiReply)['daily'])->toBe(0)
        ->and(UsageEvent::count())->toBe(0)
        ->and(UsageCharge::count())->toBe(0);
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
        ->and($second->text)->toContain('وصلت إلى الحدّ')
        ->and(UsageEvent::count())->toBe(1); // only the real AI call cost us anything
});

it('keeps the cost event when the quota race is lost at the boundary (cost incurred, quota denied)', function () {
    $subscriber = billingSubscriber(billingPlan(['daily' => 1, 'monthly' => 50]));
    fakeGroqOk('رد');
    [$conversation, $message] = meteredMessage($subscriber);

    // Simulate a concurrent message taking the last slot AFTER the pre-check
    // passed and the provider was called: the counter is already full when
    // charge() runs for this message.
    $inner = new class(app(AiAgentOrchestrator::class), $subscriber) implements AgentOrchestrator
    {
        public function __construct(private AgentOrchestrator $real, private User $subscriber) {}

        public function handle(User $user, Conversation $conversation, Message $message): AgentResponseData
        {
            $reply = $this->real->handle($user, $conversation, $message);
            app(UsageEngine::class)->charge($this->subscriber, UsageDimension::AiReply, 'concurrent-winner');

            return $reply;
        }
    };

    $reply = (new MeteredAgentOrchestrator($inner, app(UsageEngine::class), app(UsageLimitResponder::class), app(UsageRecorder::class)))
        ->handle($subscriber, $conversation, $message);

    expect($reply->metadata['usage']['denied'])->toBe('limit_reached')
        // The provider was called and consumed tokens → the ledger keeps the cost.
        ->and(UsageEvent::count())->toBe(1)
        ->and(app(UsageEngine::class)->usage($subscriber, UsageDimension::AiReply)['daily'])->toBe(1);
});

// ---- recording is independent of enforcement (billing.enforce = false) ----

it('records the ledger row but touches no counters or charges when enforcement is off', function () {
    config(['billing.enforce' => false]);
    $subscriber = billingSubscriber(billingPlan(['daily' => 1, 'monthly' => 1]));
    fakeGroqOk('رد');

    [$c1, $m1] = meteredMessage($subscriber, 'أ');
    [$c2, $m2] = meteredMessage($subscriber, 'ب');
    metered()->handle($subscriber, $c1, $m1);
    metered()->handle($subscriber, $c2, $m2); // would exceed daily=1 if enforced

    Http::assertSentCount(2); // never blocked
    expect(UsageEvent::count())->toBe(2) // both costs recorded
        ->and(UsageCounter::count())->toBe(0)
        ->and(UsageCharge::count())->toBe(0);
});

it('records once on a retry of the same message even with enforcement off', function () {
    config(['billing.enforce' => false]);
    $subscriber = billingSubscriber(billingPlan());
    fakeGroqOk('رد');
    [$conversation, $message] = meteredMessage($subscriber);

    metered()->handle($subscriber, $conversation, $message);
    metered()->handle($subscriber, $conversation, $message);

    expect(UsageEvent::count())->toBe(1);
});
