<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ChannelType;
use App\Enums\MessageType;
use Carbon\CarbonImmutable;

/**
 * Channel-agnostic representation of a single inbound message.
 *
 * Adapters normalize raw provider payloads (WhatsApp webhook, web simulator,
 * …) into this DTO so the rest of the pipeline never sees provider specifics.
 */
final readonly class InboundMessageData
{
    /**
     * @param  array<string, mixed>|null  $media  normalized media descriptor (path/mime/…)
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public ChannelType $channel,
        public string $externalMessageId,
        public string $externalUserId,
        public MessageType $type,
        public ?string $text = null,
        public ?array $media = null,
        public array $metadata = [],
        public ?CarbonImmutable $receivedAt = null,
    ) {}

    public function receivedAt(): CarbonImmutable
    {
        return $this->receivedAt ?? CarbonImmutable::now();
    }
}
