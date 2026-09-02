<?php

declare(strict_types=1);

namespace App\Channels;

use App\Contracts\ChannelAdapter;
use App\Data\InboundMessageData;
use App\Data\OutboundMessageData;
use App\Enums\ChannelType;
use App\Enums\MessageType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Local, network-free channel used by the /dev/chat simulator.
 *
 * Inbound: builds an InboundMessageData from the simulator's simple payload.
 * Outbound: a no-op — the web page reads replies straight from the database,
 * so there is nothing external to deliver to.
 */
class WebSimulatorChannelAdapter implements ChannelAdapter
{
    public function channel(): ChannelType
    {
        return ChannelType::Web;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function toInbound(array $payload): InboundMessageData
    {
        return new InboundMessageData(
            channel: ChannelType::Web,
            externalMessageId: (string) ($payload['external_message_id'] ?? 'web-'.Str::uuid()),
            externalUserId: (string) $payload['external_user_id'],
            type: MessageType::Text,
            text: isset($payload['text']) ? (string) $payload['text'] : null,
            media: null,
            metadata: (array) ($payload['metadata'] ?? []),
            receivedAt: CarbonImmutable::now(),
        );
    }

    public function send(OutboundMessageData $message): void
    {
        // No external transport: the simulator UI renders the persisted reply.
        // Intentionally does nothing (and logs nothing sensitive).
    }
}
