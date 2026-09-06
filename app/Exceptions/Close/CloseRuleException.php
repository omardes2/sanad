<?php

declare(strict_types=1);

namespace App\Exceptions\Close;

use RuntimeException;

/** A close / reopen request breaks a rule (typed confirmation, state, month contract, idempotency conflict…). Nothing written. */
final class CloseRuleException extends RuntimeException
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
