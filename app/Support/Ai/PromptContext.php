<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Data\Ai\AiMessage;

/**
 * A mutable accumulator that context contributors write into. It collects
 * ordered system segments (persona, and later: user memory, tool descriptions)
 * and the ordered chat turns. The PromptBuilder finalizes it into an AiRequest.
 *
 * Keeping system content as an ordered list of segments — rather than one fixed
 * string — is what lets memory/tools be layered in later without touching the
 * persona or the orchestrator.
 */
final class PromptContext
{
    /** @var list<string> */
    private array $systemSegments = [];

    /** @var list<AiMessage> */
    private array $messages = [];

    public function addSystem(string $segment): void
    {
        $segment = trim($segment);

        if ($segment !== '') {
            $this->systemSegments[] = $segment;
        }
    }

    public function addMessage(AiMessage $message): void
    {
        $this->messages[] = $message;
    }

    /**
     * The combined system prompt (segments joined), or null if none.
     */
    public function systemPrompt(): ?string
    {
        return $this->systemSegments === [] ? null : implode("\n\n", $this->systemSegments);
    }

    /**
     * @return list<AiMessage>
     */
    public function messages(): array
    {
        return $this->messages;
    }
}
