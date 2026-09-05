<?php

declare(strict_types=1);

namespace App\Agents;

use App\Contracts\AgentOrchestrator;
use App\Contracts\Ai\SupportsChat;
use App\Data\AgentResponseData;
use App\Data\Ai\Catalog\RoutingContext;
use App\Enums\AiOperation;
use App\Enums\MessageType;
use App\Exceptions\Ai\AiConfigurationException;
use App\Exceptions\Ai\AiException;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Ai\PromptBuilder;
use App\Services\Ai\SanadAiRouter;
use App\Support\Ai\ContextRequest;
use App\Support\SafeError;
use Illuminate\Support\Facades\Log;

/**
 * AI-backed implementation of the message pipeline's AgentOrchestrator contract.
 *
 * It is a drop-in replacement for PlaceholderAgentOrchestrator: the channel
 * transport and MessageProcessor are unchanged. Responsibilities are separated —
 * this class orchestrates (build context → route → call provider → handle
 * failure), PromptBuilder assembles context, SanadAiRouter picks the
 * (provider, model) for the operation, and the provider talks to one backend.
 * Nothing here names a vendor: switching the primary model is a catalog change.
 *
 * Failure handling (never crashes the pipeline, never sends a nonsense reply):
 *  - retryable errors (timeout/429/5xx) under failure_behavior=retry are
 *    rethrown so the queue retries with backoff (the user eventually gets a real
 *    answer);
 *  - non-retryable errors (including "no configured route"), or
 *    failure_behavior=reply, return one clear Arabic temporary-failure message.
 * Nothing sensitive (keys, user content) is ever logged.
 */
class AiAgentOrchestrator implements AgentOrchestrator
{
    public function __construct(
        private readonly SanadAiRouter $router,
        private readonly PromptBuilder $promptBuilder,
    ) {}

    public function handle(User $user, Conversation $conversation, Message $message): AgentResponseData
    {
        $request = $this->promptBuilder->build(new ContextRequest($user, $conversation, $message));

        $providerName = (string) config('ai.provider', 'groq');
        $model = null;
        $routedModel = null;

        try {
            $route = $this->router->route(AiOperation::Chat, new RoutingContext(user: $user));
            $provider = $route->provider;
            $providerName = $provider->name();
            $model = $route->model;
            $routedModel = $route->model;

            if (! $provider instanceof SupportsChat) {
                throw AiConfigurationException::unsupportedOperation($providerName, AiOperation::Chat);
            }

            $response = $provider->chat($request->withModel($model));
        } catch (AiException $e) {
            return $this->onFailure($e, $providerName, $conversation, $message);
        }

        $model = $response->model ?? $model;

        Log::info('sanad.ai.replied', [
            'provider' => $providerName,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'model' => $model,
            'prompt_tokens' => $response->promptTokens,
            'completion_tokens' => $response->completionTokens,
            'cached_tokens' => $response->cachedTokens,
            'duration_ms' => $response->durationMs,
        ]);

        return new AgentResponseData(
            text: $response->text,
            type: MessageType::Text,
            metadata: ['ai' => [
                'provider' => $providerName,
                'model' => $model,
                // What the router asked for — the ledger resolves aliases from
                // the reported model first, then this.
                'routed_model' => $routedModel,
                'operation' => AiOperation::Chat->value,
                'prompt_tokens' => $response->promptTokens,
                'completion_tokens' => $response->completionTokens,
                'cached_tokens' => $response->cachedTokens,
                'duration_ms' => $response->durationMs,
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
