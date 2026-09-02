<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\InboundMessageData;
use App\Data\OutboundMessageData;
use App\Enums\ChannelType;

/**
 * A channel adapter isolates all provider-specific concerns for one channel:
 * it normalizes raw inbound payloads into an InboundMessageData, and it
 * delivers OutboundMessageData back to the user.
 */
interface ChannelAdapter
{
    /**
     * The channel this adapter handles.
     */
    public function channel(): ChannelType;

    /**
     * Normalize a raw, provider-specific inbound payload into the pipeline DTO.
     *
     * @param  array<string, mixed>  $payload
     */
    public function toInbound(array $payload): InboundMessageData;

    /**
     * Deliver a reply to the user through this channel.
     */
    public function send(OutboundMessageData $message): void;
}
