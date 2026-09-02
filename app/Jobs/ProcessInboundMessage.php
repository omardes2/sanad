<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Channels\ChannelRegistry;
use App\Contracts\AgentOrchestrator;
use App\Data\OutboundMessageData;
use App\Enums\MessageDirection;
use App\Enums\MessageProcessingStatus;
use App\Models\Message;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Processes one inbound message off the "messages" queue:
 *   queued → processing → (reply saved once → delivered) → processed | failed
 *
 * Idempotency of the reply is guaranteed at the DATABASE level by a UNIQUE
 * constraint on messages.in_reply_to_message_id (one reply per inbound). The
 * reply row is therefore created exactly once and reused on every retry.
 *
 * Delivery (the external ChannelAdapter::send) happens OUTSIDE any database
 * transaction. The inbound message is marked "processed" only after delivery
 * succeeds; a delivery failure re-throws so the queue retries, reusing the
 * same reply row and re-attempting the send until it is delivered.
 */
class ProcessInboundMessage implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [5, 15, 30];

    public int $uniqueFor = 300;

    public function __construct(public int $messageId)
    {
        $this->onQueue('messages');
    }

    public function uniqueId(): string
    {
        return 'process-inbound-message:'.$this->messageId;
    }

    public function handle(ChannelRegistry $channels, AgentOrchestrator $agent): void
    {
        /** @var Message|null $inbound */
        $inbound = Message::with(['conversation.channelAccount', 'user'])->find($this->messageId);

        if ($inbound === null || $inbound->direction !== MessageDirection::Inbound) {
            return;
        }

        // Already fully handled (reply delivered): nothing to do.
        if ($inbound->processing_status === MessageProcessingStatus::Processed) {
            return;
        }

        $inbound->forceFill(['processing_status' => MessageProcessingStatus::Processing])->save();

        // Create the reply row exactly once (DB-enforced), or reuse it on retry.
        $reply = $this->resolveReply($inbound, $agent);

        // Deliver outside any DB transaction. Re-send while the reply is not yet
        // marked delivered ("processed"); a no-op adapter (web) succeeds instantly.
        if ($reply->processing_status !== MessageProcessingStatus::Processed) {
            $account = $inbound->conversation->channelAccount;

            $channels->for($account->channel)->send(new OutboundMessageData(
                channel: $account->channel,
                externalUserId: $account->external_identifier,
                type: $reply->type,
                text: $reply->text_content,
                metadata: ['in_reply_to_message_id' => $inbound->id],
            ));

            // Delivery succeeded (no exception): record it.
            $reply->forceFill([
                'processing_status' => MessageProcessingStatus::Processed,
                'processed_at' => now(),
            ])->save();
        }

        // Inbound is "processed" only after the reply was delivered.
        $inbound->forceFill([
            'processing_status' => MessageProcessingStatus::Processed,
            'processed_at' => now(),
        ])->save();

        $inbound->conversation->forceFill(['last_message_at' => now()])->save();
    }

    /**
     * Return the existing reply for this inbound message, or create it once.
     * The agent is invoked only when creating; a concurrent creator is handled
     * by catching the UNIQUE violation and reusing the row it inserted.
     */
    private function resolveReply(Message $inbound, AgentOrchestrator $agent): Message
    {
        $existing = Message::query()
            ->where('in_reply_to_message_id', $inbound->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $response = $agent->handle($inbound->user, $inbound->conversation, $inbound);

        try {
            return Message::create([
                'conversation_id' => $inbound->conversation_id,
                'user_id' => $inbound->user_id,
                'direction' => MessageDirection::Outbound,
                'type' => $response->type,
                'external_message_id' => null,
                'in_reply_to_message_id' => $inbound->id,
                'text_content' => $response->text,
                'metadata' => $response->metadata,
                'processing_status' => MessageProcessingStatus::Queued, // pending delivery
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent worker created the reply first — reuse it, never a 2nd row.
            return Message::query()
                ->where('in_reply_to_message_id', $inbound->id)
                ->firstOrFail();
        }
    }

    public function failed(?Throwable $exception): void
    {
        // Record the final failed state with a safe, non-sensitive note.
        // Never logs message content — only the message id and error class.
        $safe = $exception === null
            ? 'unknown error'
            : class_basename($exception).': '.mb_substr($exception->getMessage(), 0, 300);

        Message::where('id', $this->messageId)->update([
            'processing_status' => MessageProcessingStatus::Failed->value,
            'metadata->last_error' => $safe,
        ]);

        Log::warning('sanad.inbound.failed', [
            'message_id' => $this->messageId,
            'error' => $safe,
        ]);
    }
}
