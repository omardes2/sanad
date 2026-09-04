<?php

declare(strict_types=1);

namespace App\Exceptions\Ai;

/**
 * The provider did not respond within the configured timeout. Transient.
 */
final class AiTimeoutException extends AiException
{
    public function retryable(): bool
    {
        return true;
    }
}
