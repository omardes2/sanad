<?php

declare(strict_types=1);

namespace App\Data\Ai;

use App\Enums\AiOperation;

/**
 * A provider-agnostic AI request.
 *
 * The model is NOT chosen by the caller: the router resolves a (provider, model)
 * for the operation from the catalog and stamps it via withModel(). A request
 * without a model falls back to the provider's configured default, so existing
 * callers keep working. Tools are provider-agnostic AiToolDefinition values —
 * each provider translates them to its own wire format.
 *
 * @param  list<AiMessage>  $messages
 * @param  list<AiToolDefinition>  $tools
 */
final readonly class AiRequest
{
    /**
     * @param  list<AiMessage>  $messages
     * @param  list<AiToolDefinition>  $tools
     */
    public function __construct(
        public array $messages,
        public float $temperature,
        public int $maxOutputTokens,
        public int $timeout,
        public ?string $model = null,
        public AiOperation $operation = AiOperation::Chat,
        public array $tools = [],
    ) {}

    /**
     * The same request bound to a routed model (immutable copy).
     */
    public function withModel(string $model): self
    {
        return new self(
            messages: $this->messages,
            temperature: $this->temperature,
            maxOutputTokens: $this->maxOutputTokens,
            timeout: $this->timeout,
            model: $model,
            operation: $this->operation,
            tools: $this->tools,
        );
    }

    /**
     * @param  list<AiToolDefinition>  $tools
     */
    public function withTools(array $tools): self
    {
        return new self(
            messages: $this->messages,
            temperature: $this->temperature,
            maxOutputTokens: $this->maxOutputTokens,
            timeout: $this->timeout,
            model: $this->model,
            operation: $this->operation,
            tools: $tools,
        );
    }

    public function hasTools(): bool
    {
        return $this->tools !== [];
    }

    /**
     * @return list<array{role: string, content: string, tool_call_id?: string}>
     */
    public function messagesArray(): array
    {
        return array_map(static fn (AiMessage $m): array => $m->toArray(), $this->messages);
    }
}
