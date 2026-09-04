<?php

declare(strict_types=1);

namespace App\Support\Ai\Contributors;

use App\Contracts\Ai\ContextContributor;
use App\Data\Ai\AiMessage;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Models\Message;
use App\Support\Ai\ContextRequest;
use App\Support\Ai\PromptContext;

/**
 * Adds recent conversation turns as chat history.
 *
 * PRIVACY: the query is scoped to THIS conversation only ($request->conversation),
 * which belongs to a single subscriber — one user's messages can never leak into
 * another user's prompt. Only the most recent N text turns are included (bounded
 * context), oldest first, so the current inbound message is the final user turn.
 */
final class ConversationHistoryContributor implements ContextContributor
{
    public function contribute(PromptContext $context, ContextRequest $request): void
    {
        $limit = max(1, (int) config('ai.history_limit', 10));

        $messages = $request->conversation
            ->messages()
            ->where('type', MessageType::Text)
            ->latest('id')
            ->take($limit)
            ->get()
            ->reverse();

        /** @var Message $message */
        foreach ($messages as $message) {
            $content = trim((string) $message->text_content);

            if ($content === '') {
                continue;
            }

            $context->addMessage(
                $message->direction === MessageDirection::Outbound
                    ? AiMessage::assistant($content)
                    : AiMessage::user($content),
            );
        }
    }
}
