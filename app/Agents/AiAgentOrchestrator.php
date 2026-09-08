<?php

declare(strict_types=1);

namespace App\Agents;

use App\Contracts\AgentOrchestrator;
use App\Contracts\Ai\SupportsChat;
use App\Contracts\Ai\SupportsTools;
use App\Data\AgentResponseData;
use App\Data\Ai\AiMessage;
use App\Data\Ai\AiRequest;
use App\Data\Ai\AiResponse;
use App\Data\Ai\Catalog\RoutingContext;
use App\Data\Ai\ToolResult;
use App\Enums\AiOperation;
use App\Enums\MessageType;
use App\Exceptions\Ai\AiConfigurationException;
use App\Exceptions\Ai\AiException;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Ai\PromptBuilder;
use App\Services\Ai\SanadAiRouter;
use App\Services\Ai\ToolTurnRunner;
use App\Services\Settings\SettingsRepository;
use App\Support\Ai\ContextRequest;
use App\Support\SafeError;
use App\Support\Tools\ToolCatalog;
use App\Support\Tools\ToolTurnBudget;
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
        private readonly SettingsRepository $settings,
        private readonly ToolCatalog $catalog,
        private readonly ToolTurnRunner $tools,
    ) {}

    public function handle(User $user, Conversation $conversation, Message $message): AgentResponseData
    {
        $request = $this->promptBuilder->build(new ContextRequest($user, $conversation, $message));

        $providerName = (string) config('ai.provider', 'groq');
        $model = null;
        $routedModel = null;

        try {
            // Cost guardrail foundation: a known estimate above this is skipped.
            $maxUnitCost = $this->settings->get('ai.guardrails.max_cost_per_request');

            $route = $this->router->route(AiOperation::Chat, new RoutingContext(
                user: $user,
                maxUnitCost: $maxUnitCost === null ? null : (float) $maxUnitCost,
            ));
            $provider = $route->provider;
            $providerName = $provider->name();
            $model = $route->model;
            $routedModel = $route->model;

            if (! $provider instanceof SupportsChat) {
                throw AiConfigurationException::unsupportedOperation($providerName, AiOperation::Chat);
            }

            return $this->runTurn($provider, $providerName, $request->withModel($model), $model, $routedModel, $conversation, $message);
        } catch (AiException $e) {
            return $this->onFailure($e, $providerName, $conversation, $message);
        }
    }

    /**
     * The bounded turn: at most 3 provider calls, at most 2 tool rounds and at
     * most 3 tool invocations for one inbound message — counters the server owns
     * and nothing in a provider response can raise or reset.
     *
     *   call 1  the user's turn: answer, or propose tools
     *   call 2  sees round-1 results: answer, or propose the second and LAST round
     *   call 3  final synthesis only, tool calling DISABLED
     *
     * A proposed batch runs only if the WHOLE of it fits the remaining
     * invocation budget; an oversized batch is refused entirely — never
     * partially — and the turn falls through to a final call with tools off and
     * the bounded reason `tool_budget_exceeded`, so the model answers honestly
     * instead of pretending the tools ran.
     *
     * @throws AiException
     */
    private function runTurn(
        SupportsChat $provider,
        string $providerName,
        AiRequest $request,
        ?string $model,
        ?string $routedModel,
        Conversation $conversation,
        Message $message,
    ): AgentResponseData {
        $budget = new ToolTurnBudget;
        $offered = $provider instanceof SupportsTools ? $this->catalog->expose() : [];
        $calls = [];
        $response = null;

        while ($budget->mayCallProvider()) {
            $withTools = $offered !== [] && $budget->mayOfferTools();
            $index = $budget->countProviderCall();
            $response = $provider->chat($withTools ? $request->withTools($offered) : $request->withTools([]));
            $model = $response->model ?? $model;
            $calls[] = self::callFacts($index, $providerName, $model, $routedModel, $response, $withTools);

            if (! $withTools || ! $response->hasToolCalls()) {
                break;
            }

            $batch = $response->toolCalls;

            if (! $budget->fits(count($batch))) {
                // All-or-nothing: nothing is executed, and the model is told why.
                $request = $request->withTools([])->withMessages(array_merge($request->messages, [
                    AiMessage::assistantToolCalls($batch),
                    ...array_map(static fn ($c) => ToolResult::failed($c, ToolTurnBudget::EXCEEDED)->toMessage(), $batch),
                ]));

                continue;
            }

            $results = $this->tools->run($message, $batch, $budget);
            $budget->countRound(count($batch));

            $request = $request->withMessages(array_merge($request->messages, [
                AiMessage::assistantToolCalls($batch, $response->text),
                ...array_map(static fn (ToolResult $r) => $r->toMessage(), $results),
            ]));
        }

        Log::info('sanad.ai.replied', [
            'provider' => $providerName,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'model' => $model,
            'provider_calls' => $budget->providerCalls(),
            'tool_rounds' => $budget->toolRounds(),
            'tool_invocations' => $budget->toolInvocations(),
        ]);

        $last = $calls[count($calls) - 1];

        return new AgentResponseData(
            text: $response?->text ?? '',
            type: MessageType::Text,
            metadata: [
                // The LAST provider call, kept for every existing reader.
                'ai' => $last,
                // Every real provider call of this turn, so the ledger can record
                // each one instead of silently losing the synthesis calls.
                'ai_calls' => $calls,
                'tools' => [
                    'rounds' => $budget->toolRounds(),
                    'invocations' => $budget->toolInvocations(),
                ],
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function callFacts(int $index, string $providerName, ?string $model, ?string $routedModel, AiResponse $response, bool $withTools): array
    {
        return [
            'sequence' => $index,
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
            'tools_offered' => $withTools,
        ];
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
        if ($exception->retryable() && $this->settings->get('ai.failure_behavior') === 'retry') {
            throw $exception;
        }

        // Permanent failure (or reply policy): one clear temporary-failure message.
        return new AgentResponseData(
            text: (string) $this->settings->get('ai.fallback_message'),
            type: MessageType::Text,
            metadata: ['ai' => ['failed' => true, 'provider' => $providerName]],
        );
    }
}
