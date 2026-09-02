<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\AgentResponseData;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;

/**
 * The "brain": given a user, their conversation and the inbound message,
 * produce a reply. Sprint 0C ships a deterministic placeholder; a real
 * AI-backed implementation will replace it in a later sprint.
 */
interface AgentOrchestrator
{
    public function handle(User $user, Conversation $conversation, Message $message): AgentResponseData;
}
