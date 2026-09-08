<?php

declare(strict_types=1);

namespace App\Data\Tools;

use App\Models\Message;
use App\Models\User;
use App\Support\Tools\CanonicalInput;
use App\Support\Tools\InvocationKey;
use App\Support\Tools\ToolDefinition;

/**
 * ONE intended tool call, fully resolved from trusted server context (Phase F2).
 *
 * Nothing here can come from the model or the provider except the tool
 * ARGUMENTS, and those have already been validated and canonicalised against
 * the tool's closed input schema. In particular:
 *
 *  - the SUBSCRIBER is the owner of the stored message, never an id in the
 *    payload;
 *  - the CONVERSATION is the message's own conversation, never a payload field;
 *  - the CALL INDEX comes from the deterministic plan, never from the caller;
 *  - the IDEMPOTENCY KEY is derived from those persisted facts.
 *
 * A request is built only by `ToolCallPlan`, so there is no way to assemble one
 * whose ownership was not taken from the message row.
 */
final readonly class ToolCallRequest
{
    public function __construct(
        public Message $message,
        public User $subscriber,
        public ToolDefinition $definition,
        public int $callIndex,
        public CanonicalInput $input,
        public InvocationKey $key,
    ) {}

    public function toolKey(): string
    {
        return $this->definition->key->value();
    }
}
