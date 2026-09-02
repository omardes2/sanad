<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when code attempts to use an external integration that is present
 * only as a skeleton and has not been enabled yet (e.g. sending a real
 * WhatsApp message before the Meta integration exists).
 */
class IntegrationDisabledException extends RuntimeException
{
    public static function for(string $integration): self
    {
        return new self("The \"{$integration}\" integration is not enabled yet. This is a skeleton; real sending is intentionally disabled in this sprint.");
    }
}
