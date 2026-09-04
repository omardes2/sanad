<?php

declare(strict_types=1);

namespace App\Exceptions\Ai;

/**
 * A non-retryable provider error: a 4xx (bad request/auth) or a structurally
 * invalid / empty completion. Retrying will not help — fall back to a safe
 * reply instead.
 */
final class AiRequestException extends AiException
{
    public function retryable(): bool
    {
        return false;
    }
}
