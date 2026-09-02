<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ChannelType;
use App\Enums\MessageType;

/**
 * Channel-agnostic representation of a reply SANAD sends back to a user.
 * A ChannelAdapter turns this into a real provider send (or, for the web
 * simulator, a no-op since the page reads replies straight from the DB).
 */
final readonly class OutboundMessageData
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public ChannelType $channel,
        public string $externalUserId,
        public MessageType $type,
        public ?string $text = null,
        public array $metadata = [],
    ) {}
}
