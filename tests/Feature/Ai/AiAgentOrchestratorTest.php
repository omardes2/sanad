<?php

declare(strict_types=1);

use App\Agents\AiAgentOrchestrator;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Exceptions\Ai\AiException;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    aiConfigure();
});

function groqReply(string $content, int $status = 200): array
{
    return ['api.groq.com/*' => Http::response([
        'model' => 'llama-3.3-70b-versatile',
        'choices' => [['message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 4],
    ], $status)];
}

/**
 * @return array{0: User, 1: Conversation, 2: Message}
 */
function aiConversation(string $inboundText = 'كيف حالك؟'): array
{
    $user = User::factory()->create(['is_admin' => false]);
    $account = ChannelAccount::factory()->for($user)->create();
    $conversation = Conversation::factory()->for($user)->create(['channel_account_id' => $account->id]);
    $message = Message::factory()->for($user)->for($conversation)->create([
        'direction' => MessageDirection::Inbound,
        'type' => MessageType::Text,
        'text_content' => $inboundText,
    ]);

    return [$user, $conversation, $message];
}

it('returns the AI completion as the reply', function () {
    Http::fake(groqReply('أنا بخير، شكرًا لسؤالك!'));
    [$user, $conversation, $message] = aiConversation();

    $reply = app(AiAgentOrchestrator::class)->handle($user, $conversation, $message);

    expect($reply->text)->toBe('أنا بخير، شكرًا لسؤالك!')
        ->and($reply->type)->toBe(MessageType::Text);
});

it('includes the Arabic persona and the user message in the prompt', function () {
    Http::fake(groqReply('رد'));
    [$user, $conversation, $message] = aiConversation('متى موعد اجتماعي؟');

    app(AiAgentOrchestrator::class)->handle($user, $conversation, $message);

    Http::assertSent(function ($request) {
        $messages = $request['messages'];
        $system = $messages[0];

        return $system['role'] === 'system'
            && str_contains($system['content'], 'سَنَد')
            && collect($messages)->contains(fn ($m) => $m['role'] === 'user' && $m['content'] === 'متى موعد اجتماعي؟');
    });
});

it('never leaks another conversation into the prompt (privacy)', function () {
    Http::fake(groqReply('رد'));

    // Target conversation with a private earlier turn.
    [$user, $conversation] = aiConversation('SECRET-A first turn');
    $current = Message::factory()->for($user)->for($conversation)->create([
        'direction' => MessageDirection::Inbound,
        'type' => MessageType::Text,
        'text_content' => 'سؤال جديد',
    ]);

    // A DIFFERENT subscriber's conversation with its own private message.
    [$otherUser, $otherConversation] = aiConversation('SECRET-B other user');

    app(AiAgentOrchestrator::class)->handle($user, $conversation, $current);

    Http::assertSent(function ($request) {
        $blob = json_encode($request['messages'], JSON_UNESCAPED_UNICODE);

        return str_contains($blob, 'SECRET-A first turn')
            && ! str_contains($blob, 'SECRET-B other user');
    });
});

it('rethrows a retryable failure under the retry policy so the queue retries', function () {
    config(['ai.failure_behavior' => 'retry']);
    Http::fake(groqReply('', 503)); // 5xx → retryable
    [$user, $conversation, $message] = aiConversation();

    expect(fn () => app(AiAgentOrchestrator::class)->handle($user, $conversation, $message))
        ->toThrow(AiException::class);
});

it('sends the safe fallback message on a retryable failure under the reply policy', function () {
    config(['ai.failure_behavior' => 'reply']);
    Http::fake(groqReply('', 503));
    [$user, $conversation, $message] = aiConversation();

    $reply = app(AiAgentOrchestrator::class)->handle($user, $conversation, $message);

    expect($reply->text)->toBe(config('ai.fallback_message'))
        ->and($reply->metadata['ai']['failed'])->toBeTrue();
});

it('sends the safe fallback message on a non-retryable failure even under retry policy', function () {
    config(['ai.failure_behavior' => 'retry']);
    Http::fake(groqReply('', 401)); // 4xx → non-retryable
    [$user, $conversation, $message] = aiConversation();

    $reply = app(AiAgentOrchestrator::class)->handle($user, $conversation, $message);

    expect($reply->text)->toBe(config('ai.fallback_message'));
});
