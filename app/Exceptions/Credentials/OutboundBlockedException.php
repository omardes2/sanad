<?php

declare(strict_types=1);

namespace App\Exceptions\Credentials;

use RuntimeException;

/**
 * An outbound request to a candidate URL was refused by the SSRF policy at
 * call time (scheme/host/resolved address). Message lists the policy reason
 * only.
 */
final class OutboundBlockedException extends RuntimeException
{
    /**
     * @param  list<string>  $errors
     */
    public static function because(array $errors): self
    {
        return new self(implode(' ', $errors));
    }
}
