<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\MessageType;

/**
 * The reply an AgentOrchestrator produces for an inbound message.
 */
final readonly class AgentResponseData
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $text,
        public MessageType $type = MessageType::Text,
        public array $metadata = [],
    ) {}
}
