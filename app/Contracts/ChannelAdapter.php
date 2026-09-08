<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\ChannelDeliveryResult;
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
     * Deliver a message to the user through this channel, returning the
     * delivery status (and provider message id, if any). Throws on failure so
     * the caller can retry.
     *
     * `$retryTransient` is the REACTIVE default: a reply may be retried inside
     * the adapter because a duplicate reply to a question the user just asked
     * is nearly harmless. A PROACTIVE sender (reminders) passes false, so one
     * dispatch attempt is exactly one physical request and the retry policy
     * lives where it is counted and observable.
     */
    public function send(OutboundMessageData $message, bool $retryTransient = true): ChannelDeliveryResult;
}
