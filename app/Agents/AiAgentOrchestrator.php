<?php

declare(strict_types=1);

namespace App\Agents;

use App\Contracts\AgentOrchestrator;
use App\Data\AgentResponseData;
use App\Enums\MessageType;
use App\Exceptions\Ai\AiException;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Ai\AiManager;
use App\Services\Ai\PromptBuilder;
use App\Support\Ai\ContextRequest;
use App\Support\SafeError;
use Illuminate\Support\Facades\Log;

/**
 * AI-backed implementation of the message pipeline's AgentOrchestrator contract.
 *
 * It is a drop-in replacement for PlaceholderAgentOrchestrator: the WhatsApp
 * transport and MessageProcessor are unchanged. Responsibilities are separated —
 * this class orchestrates (build context → call provider → handle failure) while
 * PromptBuilder assembles context and the AiProvider talks to one backend.
 *
 * Failure handling (never crashes the pipeline, never sends a nonsense reply):
 *  - retryable errors (timeout/429/5xx) under failure_behavior=retry are
 *    rethrown so the queue retries with backoff (the user eventually gets a real
 *    answer);
 *  - non-retryable errors, or failure_behavior=reply, return one clear Arabic
 *    temporary-failure message.
 * Nothing sensitive (keys, user content) is ever logged.
 */
class AiAgentOrchestrator implements AgentOrchestrator
{
    public function __construct(
        private readonly AiManager $manager,
        private readonly PromptBuilder $promptBuilder,
    ) {}

    public function handle(User $user, Conversation $conversation, Message $message): AgentResponseData
    {
        $request = $this->promptBuilder->build(new ContextRequest($user, $conversation, $message));

        $providerName = (string) config('ai.provider', 'groq');

        try {
            $provider = $this->manager->provider();
            $providerName = $provider->name();
            $response = $provider->chat($request);
        } catch (AiException $e) {
            return $this->onFailure($e, $providerName, $conversation, $message);
        }

        Log::info('sanad.ai.replied', [
            'provider' => $providerName,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'model' => $response->model,
            'prompt_tokens' => $response->promptTokens,
            'completion_tokens' => $response->completionTokens,
        ]);

        return new AgentResponseData(
            text: $response->text,
            type: MessageType::Text,
            metadata: ['ai' => [
                'provider' => $providerName,
                'model' => $response->model,
                'prompt_tokens' => $response->promptTokens,
                'completion_tokens' => $response->completionTokens,
            ]],
        );
    }

    private function onFailure(
        AiException $exception,
        string $providerName,
        Conversation $conversation,
        Message $message,
    ): AgentResponseData {
        Log::warning('sanad.ai.failed', [
            'provider' => $providerName,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'retryable' => $exception->retryable(),
            'error' => SafeError::summarize($exception),
        ]);

        // Transient failure + retry policy → let the queue retry (ProcessInboundMessage
        // has tries/backoff). No reply row is created until a retry succeeds.
        if ($exception->retryable() && config('ai.failure_behavior', 'retry') === 'retry') {
            throw $exception;
        }

        // Permanent failure (or reply policy): one clear temporary-failure message.
        return new AgentResponseData(
            text: (string) config('ai.fallback_message'),
            type: MessageType::Text,
            metadata: ['ai' => ['failed' => true, 'provider' => $providerName]],
        );
    }
}
