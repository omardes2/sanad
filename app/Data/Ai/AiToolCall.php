<?php

declare(strict_types=1);

namespace App\Data\Ai;

/**
 * The model's request to run a tool, already decoded from the provider's wire
 * format into a stable internal shape. The platform (ToolRunner, a later phase)
 * validates and executes it; providers never do.
 *
 * @param  array<string, mixed>  $arguments  decoded JSON arguments (empty when malformed)
 */
final readonly class AiToolCall
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments = [],
    ) {}
}
