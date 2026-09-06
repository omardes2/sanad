<?php

declare(strict_types=1);

namespace App\Exceptions\Fx;

use RuntimeException;

/** An FX request breaks a domain rule (orientation, date policy, missing rate, native currency…). Nothing written. */
final class FxRuleException extends RuntimeException
{
    public function __construct(public readonly string $rule, string $message)
    {
        parent::__construct($message);
    }

    public static function of(string $rule, string $message): self
    {
        return new self($rule, $message);
    }
}
