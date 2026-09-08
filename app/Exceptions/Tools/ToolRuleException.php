<?php

declare(strict_types=1);

namespace App\Exceptions\Tools;

use RuntimeException;

/** A tool request breaks a domain rule (unknown capability, unchanged consent, invalid reference…). Nothing written. */
final class ToolRuleException extends RuntimeException
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
