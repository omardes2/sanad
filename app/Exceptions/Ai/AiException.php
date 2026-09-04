<?php

declare(strict_types=1);

namespace App\Exceptions\Ai;

use RuntimeException;

/**
 * Base for all AI provider failures. Messages are always safe (status codes /
 * short reasons only) — never a raw provider body, API key, or user content —
 * so App\Support\SafeError may surface them.
 *
 * `retryable()` tells the orchestrator whether the queue should retry (transient
 * conditions) or fall back to a safe reply (permanent conditions).
 */
abstract class AiException extends RuntimeException
{
    public function retryable(): bool
    {
        return false;
    }
}
