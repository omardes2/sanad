<?php

declare(strict_types=1);

namespace App\Data\Ai;

/**
 * A provider-agnostic chat completion request.
 *
 * The model is intentionally NOT part of this DTO: each provider owns its own
 * model (from config) so the orchestration layer never needs to know which
 * model a provider uses. Future tool/function-calling support will extend this
 * DTO with an optional "tools" field; providers ignore fields they do not use,
 * so adding it will not break existing providers.
 *
 * @param  list<AiMessage>  $messages
 */
final readonly class AiRequest
{
    /**
     * @param  list<AiMessage>  $messages
     */
    public function __construct(
        public array $messages,
        public float $temperature,
        public int $maxOutputTokens,
        public int $timeout,
    ) {}

    /**
     * @return list<array{role: string, content: string}>
     */
    public function messagesArray(): array
    {
        return array_map(static fn (AiMessage $m): array => $m->toArray(), $this->messages);
    }
}
