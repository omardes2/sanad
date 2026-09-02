<?php

declare(strict_types=1);

namespace App\Agents;

use App\Contracts\AgentOrchestrator;
use App\Data\AgentResponseData;
use App\Enums\MessageType;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;

/**
 * Deterministic, offline stand-in for the future AI orchestrator.
 *
 * It does NOT call OpenAI or any external service. A real implementation
 * (intent understanding, tools, memory) will replace it in a later sprint.
 */
class PlaceholderAgentOrchestrator implements AgentOrchestrator
{
    public function handle(User $user, Conversation $conversation, Message $message): AgentResponseData
    {
        $text = trim((string) $message->text_content);

        if ($text === 'مرحبا') {
            return new AgentResponseData(
                text: 'أهلًا! أنا سَنَد، مساعدك الشخصي الذكي. كيف بقدر أساعدك؟',
                type: MessageType::Text,
            );
        }

        return new AgentResponseData(
            text: "تم استلام رسالتك: {$text}",
            type: MessageType::Text,
        );
    }
}
