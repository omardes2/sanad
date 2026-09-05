<?php

declare(strict_types=1);

namespace App\Exceptions\Settings;

use RuntimeException;

final class ReadOnlySettingException extends RuntimeException
{
    public static function for(string $key): self
    {
        return new self("Setting [{$key}] is read-only in this phase and cannot be changed from the database or the UI.");
    }
}
