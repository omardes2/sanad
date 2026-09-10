<?php

declare(strict_types=1);

namespace App\Exceptions\Ai;

/**
 * The provider ACCEPTED the request, did the work, and produced nothing usable
 * — an empty transcript, an empty completion.
 *
 * Kept distinct from AiRequestException because the two call for opposite
 * accounting. A 4xx means nothing was processed; this means something was, and
 * may well have been charged for. Retrying it would pay a second time for the
 * same silence, so it is not retryable — but "not retryable" here does not mean
 * "cost nothing", and callers must not collapse the two.
 */
final class AiEmptyResultException extends AiException
{
    public function retryable(): bool
    {
        return false;
    }
}
