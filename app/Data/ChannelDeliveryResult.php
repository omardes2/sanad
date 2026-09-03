<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\MessageDeliveryStatus;

/**
 * Result of ChannelAdapter::send(): the delivery status the channel reports
 * immediately, plus the provider's message id when one is returned (WhatsApp
 * returns a wamid; the web simulator returns none).
 */
final readonly class ChannelDeliveryResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public MessageDeliveryStatus $status,
        public ?string $providerMessageId = null,
        public array $metadata = [],
    ) {}
}
