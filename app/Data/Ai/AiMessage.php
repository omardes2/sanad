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
        /** Set only on Tool-role messages: which tool call this result answers. */
        public ?string $toolCallId = null,
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
     * A tool result being returned to the model (see ToolResult::toMessage()).
     */
    public static function tool(string $toolCallId, string $content): self
    {
        return new self(AiRole::Tool, $content, $toolCallId);
    }

    /**
     * OpenAI-compatible wire shape (OpenAI, Groq, Gemini OpenAI endpoint, Ollama /v1).
     *
     * @return array{role: string, content: string, tool_call_id?: string}
     */
    public function toArray(): array
    {
        $message = ['role' => $this->role->value, 'content' => $this->content];

        if ($this->role === AiRole::Tool && $this->toolCallId !== null) {
            $message['tool_call_id'] = $this->toolCallId;
        }

        return $message;
    }
}
