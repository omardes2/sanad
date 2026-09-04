<?php

declare(strict_types=1);

namespace App\Data\Ai;

/**
 * A provider-agnostic chat completion result.
 */
final readonly class AiResponse
{
    public function __construct(
        public string $text,
        public ?string $model = null,
        public ?int $promptTokens = null,
        public ?int $completionTokens = null,
        public ?string $finishReason = null,
    ) {}
}
