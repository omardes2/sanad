<?php

declare(strict_types=1);

namespace App\Data\Ai;

/**
 * The outcome of executing one AiToolCall. Provider-agnostic: toMessage() turns
 * it into the Tool-role turn any SupportsTools provider can send back to the
 * model. A failed result carries a safe, non-sensitive error string only.
 *
 * @param  array<string, mixed>  $output
 */
final readonly class ToolResult
{
    /**
     * @param  array<string, mixed>  $output
     */
    public function __construct(
        public string $toolCallId,
        public string $name,
        public bool $success,
        public array $output = [],
        public ?string $error = null,
    ) {}

    /**
     * @param  array<string, mixed>  $output
     */
    public static function ok(AiToolCall $call, array $output = []): self
    {
        return new self($call->id, $call->name, true, $output);
    }

    public static function failed(AiToolCall $call, string $safeError): self
    {
        return new self($call->id, $call->name, false, [], $safeError);
    }

    public function toMessage(): AiMessage
    {
        $payload = $this->success
            ? ['ok' => true, 'result' => $this->output]
            : ['ok' => false, 'error' => $this->error];

        return AiMessage::tool($this->toolCallId, (string) json_encode($payload, JSON_UNESCAPED_UNICODE));
    }
}
