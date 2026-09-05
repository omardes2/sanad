<?php

declare(strict_types=1);

namespace App\Data\Ai;

/**
 * A tool the model may call, described once in Sanad's own shape. Providers
 * translate it to their wire format (OpenAI-compatible "function" tools today),
 * so a tool is defined a single time and works on any SupportsTools provider.
 *
 * @param  array<string, mixed>  $parameters  JSON Schema for the arguments object
 */
final readonly class AiToolDefinition
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $parameters = ['type' => 'object', 'properties' => new \stdClass, 'required' => []],
    ) {}
}
