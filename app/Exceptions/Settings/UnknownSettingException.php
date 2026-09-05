<?php

declare(strict_types=1);

namespace App\Exceptions\Settings;

use InvalidArgumentException;

final class UnknownSettingException extends InvalidArgumentException
{
    public static function for(string $key): self
    {
        return new self("Setting [{$key}] is not registered in the Settings Registry.");
    }
}
