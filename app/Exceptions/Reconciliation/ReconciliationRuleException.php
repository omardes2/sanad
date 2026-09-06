<?php

declare(strict_types=1);

namespace App\Exceptions\Reconciliation;

use RuntimeException;

/** An invoice / reconciliation / adjustment request breaks a domain rule. Nothing was written. */
final class ReconciliationRuleException extends RuntimeException
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
