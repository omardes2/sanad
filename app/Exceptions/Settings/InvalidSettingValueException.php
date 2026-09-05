<?php

declare(strict_types=1);

namespace App\Exceptions\Settings;

use InvalidArgumentException;

final class InvalidSettingValueException extends InvalidArgumentException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(public readonly string $key, public readonly array $errors)
    {
        parent::__construct("Invalid value for setting [{$key}]: ".implode(' ', $errors));
    }
}
