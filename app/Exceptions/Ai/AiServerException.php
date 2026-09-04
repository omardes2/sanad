<?php

declare(strict_types=1);

namespace App\Exceptions\Ai;

/**
 * The provider returned a 5xx. Transient — retry with backoff.
 */
final class AiServerException extends AiException
{
    public function retryable(): bool
    {
        return true;
    }
}
