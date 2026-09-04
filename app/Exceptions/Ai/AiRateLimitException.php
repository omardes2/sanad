<?php

declare(strict_types=1);

namespace App\Exceptions\Ai;

/**
 * The provider returned HTTP 429. Transient — retry with backoff.
 */
final class AiRateLimitException extends AiException
{
    public function retryable(): bool
    {
        return true;
    }
}
