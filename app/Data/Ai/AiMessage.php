<?php

declare(strict_types=1);

namespace App\Data\Ai;

/**
 * One turn in a provider-agnostic chat request.
 */
final readonly class AiMessage
{
    /**
     * @param  list<AiToolCall>  $toolCalls  set only on an Assistant turn that proposed tools
     */
    public function __construct(
        public AiRole $role,
        public string $content,
        /** Set only on Tool-role messages: which tool call this result answers. */
        public ?string $toolCallId = null,
        public array $toolCalls = [],
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
     * The assistant turn in which the model asked for tools. It has to be sent
     * back verbatim before the Tool-role results, or the provider cannot match a
     * result to the call it answers.
     *
     * @param  list<AiToolCall>  $calls
     */
    public static function assistantToolCalls(array $calls, string $content = ''): self
    {
        return new self(AiRole::Assistant, $content, null, $calls);
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
     * @return array{role: string, content: string, tool_call_id?: string, tool_calls?: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        $message = ['role' => $this->role->value, 'content' => $this->content];

        if ($this->role === AiRole::Tool && $this->toolCallId !== null) {
            $message['tool_call_id'] = $this->toolCallId;
        }

        if ($this->role === AiRole::Assistant && $this->toolCalls !== []) {
            $message['tool_calls'] = array_map(static fn (AiToolCall $call): array => [
                'id' => $call->id,
                'type' => 'function',
                'function' => [
                    'name' => $call->name,
                    'arguments' => (string) json_encode($call->arguments, JSON_UNESCAPED_UNICODE),
                ],
            ], $this->toolCalls);
        }

        return $message;
    }
}
