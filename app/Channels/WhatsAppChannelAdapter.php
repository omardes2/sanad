<?php

declare(strict_types=1);

namespace App\Channels;

use App\Contracts\ChannelAdapter;
use App\Data\ChannelDeliveryResult;
use App\Data\InboundMessageData;
use App\Data\OutboundMessageData;
use App\Enums\ChannelType;
use App\Enums\MessageDeliveryStatus;
use App\Enums\MessageType;
use App\Exceptions\WhatsAppSendException;
use App\Support\WhatsApp\WhatsAppConfig;
use App\Support\WhatsApp\WhatsAppPhone;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * WhatsApp Cloud API adapter — TEXT transport (Sprint 0D).
 *
 * Inbound: normalizes a single WhatsApp message (with its surrounding value
 * context) into an InboundMessageData. Outbound: sends a text message via the
 * Graph API using the Laravel HTTP client. All network calls are faked in
 * tests; there is never a live call to Meta from the test suite.
 */
class WhatsAppChannelAdapter implements ChannelAdapter
{
    private const MAX_ATTEMPTS = 3;

    public function __construct(private readonly WhatsAppConfig $config) {}

    public function channel(): ChannelType
    {
        return ChannelType::WhatsApp;
    }

    /**
     * Normalize a single WhatsApp message. Expects the per-message context:
     *   [ 'message' => <message>, 'contacts' => [...], 'metadata' => [...], 'waba_id' => '...' ]
     *
     * @param  array<string, mixed>  $payload
     */
    public function toInbound(array $payload): InboundMessageData
    {
        /** @var array<string, mixed> $message */
        $message = $payload['message'] ?? [];
        /** @var array<string, mixed> $metadata */
        $metadata = $payload['metadata'] ?? [];
        /** @var list<array<string, mixed>> $contacts */
        $contacts = $payload['contacts'] ?? [];

        $from = (string) ($message['from'] ?? '');
        $e164 = WhatsAppPhone::toE164($from);

        if ($e164 === null) {
            throw new \InvalidArgumentException('WhatsApp message has an invalid sender number.');
        }

        $type = $this->mapType((string) ($message['type'] ?? ''));
        $text = $type === MessageType::Text ? ($message['text']['body'] ?? null) : null;

        return new InboundMessageData(
            channel: ChannelType::WhatsApp,
            externalMessageId: (string) ($message['id'] ?? ''),
            externalUserId: $e164,
            type: $type,
            text: $text !== null ? (string) $text : null,
            media: null,
            metadata: [
                'provider' => 'whatsapp',
                'phone_number_id' => $metadata['phone_number_id'] ?? null,
                'waba_id' => $payload['waba_id'] ?? null,
                'profile_name' => $this->profileName($contacts, $from),
                'wa_timestamp' => $message['timestamp'] ?? null,
            ],
            receivedAt: isset($message['timestamp'])
                ? CarbonImmutable::createFromTimestamp((int) $message['timestamp'])
                : CarbonImmutable::now(),
        );
    }

    /**
     * `$retryTransient` false ⇒ EXACTLY ONE physical request. A proactive
     * sender needs that: an invisible in-adapter retry after a timeout can
     * deliver a second unsolicited message that the caller never learns about,
     * and would let one counted attempt become three real sends.
     */
    public function send(OutboundMessageData $message, bool $retryTransient = true): ChannelDeliveryResult
    {
        // Fail closed if the integration is disabled or misconfigured.
        $this->config->assertCanSend();

        $recipient = ltrim($message->externalUserId, '+');
        $url = sprintf(
            '%s/%s/%s/messages',
            $this->config->graphBaseUrl,
            $this->config->graphVersion,
            $this->config->phoneNumberId(),
        );

        $payload = $this->payload($message, $recipient);
        $maxAttempts = $retryTransient ? self::MAX_ATTEMPTS : 1;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = Http::withToken($this->config->accessToken())
                    ->timeout($this->config->requestTimeout)
                    ->acceptJson()
                    ->asJson()
                    ->post($url, $payload);
            } catch (ConnectionException) {
                // Network error → retry, then give up with a safe exception.
                if ($attempt < $maxAttempts) {
                    $this->backoff($attempt);

                    continue;
                }

                throw WhatsAppSendException::network();
            }

            $status = $response->status();

            if ($response->successful()) {
                return $this->resultFromResponse($response->json());
            }

            // Retry only transient failures: 429 and 5xx.
            if ($status === 429 || $status >= 500) {
                if ($attempt < $maxAttempts) {
                    $this->backoff($attempt);

                    continue;
                }

                throw WhatsAppSendException::transient($status);
            }

            // Any other 4xx is a permanent client error — do not retry.
            throw WhatsAppSendException::rejected($status);
        }

        // Unreachable, but keeps static analysis happy.
        throw WhatsAppSendException::network();
    }

    /**
     * Free-form text, or a pre-approved template when the caller supplied one.
     *
     * @return array<string, mixed>
     */
    private function payload(OutboundMessageData $message, string $recipient): array
    {
        $base = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $recipient,
        ];

        if ($message->template !== null) {
            $components = $message->template->parameters === [] ? [] : [[
                'type' => 'body',
                'parameters' => array_map(
                    static fn (string $value): array => ['type' => 'text', 'text' => $value],
                    $message->template->parameters,
                ),
            ]];

            return $base + [
                'type' => 'template',
                'template' => array_filter([
                    'name' => $message->template->name,
                    'language' => ['code' => $message->template->language],
                    'components' => $components === [] ? null : $components,
                ], static fn ($v): bool => $v !== null),
            ];
        }

        return $base + [
            'type' => 'text',
            'text' => [
                'preview_url' => false,
                'body' => (string) $message->text,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $json
     */
    private function resultFromResponse(?array $json): ChannelDeliveryResult
    {
        $providerId = $json['messages'][0]['id'] ?? null;

        if (! is_string($providerId) || $providerId === '') {
            throw WhatsAppSendException::malformedResponse();
        }

        // Meta accepted the message; sent/delivered/read arrive via status webhooks.
        return new ChannelDeliveryResult(
            status: MessageDeliveryStatus::Accepted,
            providerMessageId: $providerId,
        );
    }

    private function backoff(int $attempt): void
    {
        // Keep the test suite fast; only sleep in real runtime.
        if (! app()->runningUnitTests()) {
            usleep(200_000 * $attempt);
        }
    }

    private function mapType(string $type): MessageType
    {
        return match ($type) {
            'text' => MessageType::Text,
            'audio' => MessageType::Audio,
            'image' => MessageType::Image,
            'document' => MessageType::Document,
            'location' => MessageType::Location,
            'interactive' => MessageType::Interactive,
            default => MessageType::System,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $contacts
     */
    private function profileName(array $contacts, string $from): ?string
    {
        foreach ($contacts as $contact) {
            if ((string) ($contact['wa_id'] ?? '') === $from) {
                $name = $contact['profile']['name'] ?? null;

                return $name !== null ? (string) $name : null;
            }
        }

        return null;
    }
}
