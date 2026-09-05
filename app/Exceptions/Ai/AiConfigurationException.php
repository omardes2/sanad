<?php

declare(strict_types=1);

namespace App\Exceptions\Ai;

use App\Enums\AiOperation;

/**
 * A provider is not fully configured (missing API key or model), an unknown
 * provider was requested, or no configured provider can serve an operation.
 * Non-retryable — needs an operator fix (config today, Sanad Admin later).
 */
final class AiConfigurationException extends AiException
{
    public static function missing(string $provider, string $field): self
    {
        return new self("AI provider [{$provider}] is missing required config: {$field}.");
    }

    public static function unknownProvider(string $provider): self
    {
        return new self("Unknown AI provider [{$provider}].");
    }

    public static function noRoute(AiOperation $operation): self
    {
        return new self("No configured AI provider can serve operation [{$operation->value}].");
    }

    public static function unsupportedOperation(string $provider, AiOperation $operation): self
    {
        return new self("AI provider [{$provider}] does not support operation [{$operation->value}].");
    }
}
