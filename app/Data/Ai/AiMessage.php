<?php

declare(strict_types=1);

namespace App\Data\Ai;

/**
 * One turn in a provider-agnostic chat request.
 */
final readonly class AiMessage
{
    public function __construct(
        public AiRole $role,
        public string $content,
    ) {}

    public static function system(string $content): self
    {
        return new self(AiRole::System, $content);
    }

    public static function user(string $content): self
    {
        return new self(AiRole::User, $content);
    }

    public static function assistant(string $content): self
    {
        return new self(AiRole::Assistant, $content);
    }

    /**
     * OpenAI-compatible wire shape (Groq, Gemini OpenAI endpoint, Ollama /v1).
     *
     * @return array{role: string, content: string}
     */
    public function toArray(): array
    {
        return ['role' => $this->role->value, 'content' => $this->content];
    }
}
