<?php

declare(strict_types=1);

namespace App\Exceptions\Credentials;

use RuntimeException;

/**
 * CREDENTIALS_KEY is missing or malformed: nothing can be sealed. Carries no
 * key material and no secret.
 */
final class VaultUnavailableException extends RuntimeException
{
    public static function missingKey(): self
    {
        return new self('الخزنة غير متاحة: CREDENTIALS_KEY غير مضبوط أو غير صالح.');
    }
}
