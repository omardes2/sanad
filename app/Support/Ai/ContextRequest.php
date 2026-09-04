<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;

/**
 * The immutable input a context contributor may read when assembling a prompt:
 * who is speaking, the conversation, and the specific inbound message.
 *
 * This is the natural place a future User Memory layer will hang off — a memory
 * contributor reads $request->user and appends remembered facts/preferences to
 * the PromptContext, with no change to the orchestrator.
 */
final readonly class ContextRequest
{
    public function __construct(
        public User $user,
        public Conversation $conversation,
        public Message $message,
    ) {}
}
