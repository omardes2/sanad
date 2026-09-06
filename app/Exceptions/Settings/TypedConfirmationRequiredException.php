<?php

declare(strict_types=1);

namespace App\Exceptions\Settings;

use RuntimeException;

/**
 * A setting that requires a typed confirmation (the new value spelled out
 * verbatim) was written without one, or with a different one. Nothing was
 * written and nothing was audited — the repository refuses before any I/O,
 * so no managed-writer path can bypass the confirmation.
 */
final class TypedConfirmationRequiredException extends RuntimeException
{
    public static function for(string $key): self
    {
        return new self("Setting [{$key}] requires the new value to be typed verbatim as confirmation; the write was refused.");
    }
}
