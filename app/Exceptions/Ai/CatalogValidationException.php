<?php

declare(strict_types=1);

namespace App\Exceptions\Ai;

use InvalidArgumentException;

final class CatalogValidationException extends InvalidArgumentException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}
