<?php

declare(strict_types=1);

namespace App\Data\Ai;

/**
 * A provider-agnostic AI result.
 *
 * Text may be empty when the model answered with tool calls only. Usage fields
 * (prompt/completion/cached tokens, wall-clock duration) are what the cost
 * ledger will meter in a later phase — recorded here so every provider reports
 * them through one shape.
 *
 * @param  list<AiToolCall>  $toolCalls
 */
final readonly class AiResponse
{
    /**
     * @param  list<AiToolCall>  $toolCalls
     */
    public function __construct(
        public string $text,
        public ?string $model = null,
        public ?int $promptTokens = null,
        public ?int $completionTokens = null,
        public ?string $finishReason = null,
        public ?int $cachedTokens = null,
        public ?int $durationMs = null,
        public array $toolCalls = [],
        public ?string $provider = null,
    ) {}

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }
}
