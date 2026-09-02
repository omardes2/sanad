<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\InboundMessageData;
use App\Data\ProcessResult;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageProcessingStatus;
use App\Enums\ProcessOutcome;
use App\Jobs\ProcessInboundMessage;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ingests a normalized inbound message:
 *   resolve sender → find/create conversation → idempotency check →
 *   persist inbound message → queue processing job (after commit).
 *
 * Returns a clear ProcessResult (accepted / duplicate / rejected). It never
 * logs message content, only non-sensitive identifiers.
 */
class MessageProcessor
{
    public function process(InboundMessageData $data): ProcessResult
    {
        if (trim($data->externalMessageId) === '') {
            return $this->reject('missing_external_message_id', $data);
        }

        $account = ChannelAccount::query()
            ->where('channel', $data->channel)
            ->where('external_identifier', $data->externalUserId)
            ->first();

        if ($account === null) {
            return $this->reject('unknown_channel_account', $data);
        }

        try {
            $result = DB::transaction(function () use ($data, $account): ProcessResult {
                $existing = Message::query()
                    ->where('external_message_id', $data->externalMessageId)
                    ->first();

                if ($existing !== null) {
                    return new ProcessResult(ProcessOutcome::Duplicate, $existing);
                }

                $conversation = $this->resolveConversation($account);

                $message = Message::create([
                    'conversation_id' => $conversation->id,
                    'user_id' => $account->user_id,
                    'direction' => MessageDirection::Inbound,
                    'type' => $data->type,
                    'external_message_id' => $data->externalMessageId,
                    'text_content' => $data->text,
                    'media_path' => $data->media['path'] ?? null,
                    'metadata' => $data->metadata,
                    'processing_status' => MessageProcessingStatus::Queued,
                ]);

                $conversation->forceFill(['last_message_at' => $message->created_at])->save();

                return new ProcessResult(ProcessOutcome::Accepted, $message);
            });
        } catch (UniqueConstraintViolationException) {
            // Race: a concurrent request inserted the same external_message_id
            // between our SELECT and INSERT. Treat as a duplicate, no new job.
            $existing = Message::query()
                ->where('external_message_id', $data->externalMessageId)
                ->first();

            Log::info('sanad.inbound.duplicate_race', $this->logContext($data));

            return new ProcessResult(ProcessOutcome::Duplicate, $existing);
        }

        if ($result->accepted() && $result->message !== null) {
            // Dispatch only after the transaction has committed so the worker
            // never races ahead of the row it needs.
            ProcessInboundMessage::dispatch($result->message->id)
                ->onQueue('messages')
                ->afterCommit();

            Log::info('sanad.inbound.accepted', $this->logContext($data) + [
                'message_id' => $result->message->id,
            ]);
        } elseif ($result->duplicate()) {
            Log::info('sanad.inbound.duplicate', $this->logContext($data));
        }

        return $result;
    }

    private function resolveConversation(ChannelAccount $account): Conversation
    {
        return Conversation::query()
            ->where('user_id', $account->user_id)
            ->where('channel_account_id', $account->id)
            ->where('status', ConversationStatus::Active)
            ->latest('id')
            ->first()
            ?? Conversation::create([
                'user_id' => $account->user_id,
                'channel_account_id' => $account->id,
                'status' => ConversationStatus::Active,
                'last_message_at' => now(),
            ]);
    }

    private function reject(string $reason, InboundMessageData $data): ProcessResult
    {
        Log::warning('sanad.inbound.rejected', $this->logContext($data) + ['reason' => $reason]);

        return new ProcessResult(ProcessOutcome::Rejected);
    }

    /**
     * Non-sensitive log context — identifiers only, never message content.
     *
     * @return array<string, mixed>
     */
    private function logContext(InboundMessageData $data): array
    {
        return [
            'channel' => $data->channel->value,
            'external_message_id' => $data->externalMessageId,
            'external_user_id' => $data->externalUserId,
            'type' => $data->type->value,
        ];
    }
}
