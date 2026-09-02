<?php

declare(strict_types=1);

namespace App\Channels;

use App\Contracts\ChannelAdapter;
use App\Data\InboundMessageData;
use App\Data\OutboundMessageData;
use App\Enums\ChannelType;
use App\Exceptions\IntegrationDisabledException;

/**
 * Skeleton adapter for WhatsApp Cloud API.
 *
 * This class exists so the channel abstraction is complete, but it does NOT
 * talk to Meta in this sprint. Any attempt to actually send throws a clear
 * IntegrationDisabledException. Inbound normalization is likewise deferred.
 */
class WhatsAppChannelAdapter implements ChannelAdapter
{
    public function channel(): ChannelType
    {
        return ChannelType::WhatsApp;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function toInbound(array $payload): InboundMessageData
    {
        throw IntegrationDisabledException::for('whatsapp');
    }

    public function send(OutboundMessageData $message): void
    {
        throw IntegrationDisabledException::for('whatsapp');
    }
}
