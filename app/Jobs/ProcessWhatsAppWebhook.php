<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Channels\WhatsAppChannelAdapter;
use App\Enums\MessageDeliveryStatus;
use App\Enums\WebhookEventStatus;
use App\Models\Message;
use App\Models\WebhookEvent;
use App\Services\MessageProcessor;
use App\Support\SafeError;
use App\Support\WhatsApp\WhatsAppConfig;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Processes one stored WhatsApp webhook envelope off the "webhooks" queue.
 *
 *   received → processing → processed | failed
 *
 * Walks every entry[] → changes[] → value, handling all messages[] and
 * statuses[] (not just the first). Text messages are handed to the existing
 * MessageProcessor (no pipeline logic is duplicated); non-text messages are
 * acknowledged and ignored. Status updates advance delivery state monotonically.
 *
 * Resilience: a single structurally-corrupt element (a non-array entry, change,
 * message or status) is skipped with a safe log so it never blocks the other,
 * valid elements. Systemic failures (DB/connection errors) still bubble so the
 * queue retries the whole envelope; re-processing is safe because it is fully
 * idempotent (WebhookEvent short-circuits once processed, MessageProcessor
 * dedups by external_message_id, and status transitions are monotonic no-ops).
 *
 * Nothing sensitive is ever logged or stored.
 */
class ProcessWhatsAppWebhook implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [5, 15, 30];

    public int $uniqueFor = 300;

    public function __construct(public int $webhookEventId)
    {
        $this->onQueue('webhooks');
    }

    public function uniqueId(): string
    {
        return 'process-whatsapp-webhook:'.$this->webhookEventId;
    }

    public function handle(WhatsAppChannelAdapter $adapter, MessageProcessor $processor, WhatsAppConfig $config): void
    {
        $event = WebhookEvent::find($this->webhookEventId);

        if ($event === null || $event->status === WebhookEventStatus::Processed) {
            return;
        }

        $event->forceFill(['status' => WebhookEventStatus::Processing])->save();

        /** @var array<string, mixed> $payload */
        $payload = $event->payload ?? [];

        foreach ($this->arrayItems($payload['entry'] ?? null) as $entry) {
            if (! is_array($entry)) {
                $this->logCorrupt($event->id, 'entry');

                continue;
            }

            $wabaId = $entry['id'] ?? null;

            foreach ($this->arrayItems($entry['changes'] ?? null) as $change) {
                if (! is_array($change)) {
                    $this->logCorrupt($event->id, 'change');

                    continue;
                }

                $value = is_array($change['value'] ?? null) ? $change['value'] : [];
                $metadata = is_array($value['metadata'] ?? null) ? $value['metadata'] : [];
                $phoneNumberId = $metadata['phone_number_id'] ?? null;

                // Ignore anything that is not for the configured number / WABA.
                if (! $this->matchesConfiguredTarget($config, $phoneNumberId, $wabaId)) {
                    Log::info('sanad.whatsapp.ignored_target', ['event_id' => $event->id]);

                    continue;
                }

                foreach ($this->arrayItems($value['messages'] ?? null) as $message) {
                    if (! is_array($message)) {
                        $this->logCorrupt($event->id, 'message');

                        continue;
                    }

                    $this->handleMessage($adapter, $processor, $message, $value, $metadata, $wabaId, $event->id);
                }

                foreach ($this->arrayItems($value['statuses'] ?? null) as $status) {
                    if (! is_array($status)) {
                        $this->logCorrupt($event->id, 'status');

                        continue;
                    }

                    $this->handleStatus($status, $event->id);
                }
            }
        }

        $event->forceFill([
            'status' => WebhookEventStatus::Processed,
            'processed_at' => now(),
        ])->save();
    }

    /**
     * @return iterable<mixed>
     */
    private function arrayItems(mixed $value): iterable
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array<string, mixed>  $value
     * @param  array<string, mixed>  $metadata
     */
    private function handleMessage(
        WhatsAppChannelAdapter $adapter,
        MessageProcessor $processor,
        array $message,
        array $value,
        array $metadata,
        mixed $wabaId,
        int $eventId,
    ): void {
        $type = (string) ($message['type'] ?? '');

        // Text only in this sprint; anything else is acknowledged and ignored.
        if ($type !== 'text') {
            Log::info('sanad.whatsapp.unsupported_message', [
                'event_id' => $eventId,
                'type' => $type,
                'wamid' => $message['id'] ?? null,
            ]);

            return;
        }

        try {
            $dto = $adapter->toInbound([
                'message' => $message,
                'contacts' => $value['contacts'] ?? [],
                'metadata' => $metadata,
                'waba_id' => $wabaId,
            ]);
        } catch (InvalidArgumentException) {
            // Invalid sender number, etc. — skip safely, never break the batch.
            Log::warning('sanad.whatsapp.invalid_message', [
                'event_id' => $eventId,
                'wamid' => $message['id'] ?? null,
            ]);

            return;
        }

        // Idempotency is enforced downstream by MessageProcessor via the unique
        // messages.external_message_id (the wamid): the same wamid arriving in
        // different envelopes yields exactly one inbound and one reply dispatch.
        $processor->process($dto);
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function handleStatus(array $status, int $eventId): void
    {
        $providerId = $status['id'] ?? null;
        $mapped = $this->mapStatus((string) ($status['status'] ?? ''));

        if (! is_string($providerId) || $providerId === '' || $mapped === null) {
            return;
        }

        $message = Message::query()->where('provider_message_id', $providerId)->first();

        if ($message === null) {
            // Status for a message we did not send (or not yet stored). Safe skip;
            // never create a message here.
            Log::info('sanad.whatsapp.status_unknown_message', [
                'event_id' => $eventId,
                'status' => $mapped->value,
            ]);

            return;
        }

        $errorCode = $mapped === MessageDeliveryStatus::Failed
            ? ($status['errors'][0]['code'] ?? null)
            : null;

        // Monotonic: no-op when not moving forward; never clears timestamps.
        $message->applyDeliveryStatus(
            $mapped,
            $errorCode !== null ? (string) $errorCode : null,
        );
    }

    private function matchesConfiguredTarget(WhatsAppConfig $config, mixed $phoneNumberId, mixed $wabaId): bool
    {
        if ($config->phoneNumberId !== null && (string) $phoneNumberId !== $config->phoneNumberId) {
            return false;
        }

        if ($config->businessAccountId !== null && (string) $wabaId !== $config->businessAccountId) {
            return false;
        }

        return true;
    }

    private function mapStatus(string $status): ?MessageDeliveryStatus
    {
        return match ($status) {
            'sent' => MessageDeliveryStatus::Sent,
            'delivered' => MessageDeliveryStatus::Delivered,
            'read' => MessageDeliveryStatus::Read,
            'failed' => MessageDeliveryStatus::Failed,
            default => null,
        };
    }

    private function logCorrupt(int $eventId, string $element): void
    {
        Log::warning('sanad.whatsapp.corrupt_element', [
            'event_id' => $eventId,
            'element' => $element,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        // SafeError never echoes a framework/DB exception message (which can
        // embed SQL bindings such as message text or phone numbers).
        $safe = SafeError::summarize($exception);

        WebhookEvent::where('id', $this->webhookEventId)->update([
            'status' => WebhookEventStatus::Failed->value,
            'error_message' => $safe,
        ]);

        Log::warning('sanad.whatsapp.webhook_failed', [
            'event_id' => $this->webhookEventId,
            'error' => $safe,
        ]);
    }
}
