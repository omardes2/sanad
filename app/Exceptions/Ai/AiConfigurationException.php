<?php

declare(strict_types=1);

namespace App\Exceptions\Ai;

/**
 * The active provider is not fully configured (missing API key or model), or an
 * unknown provider was requested. Non-retryable — needs an operator fix.
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
}
