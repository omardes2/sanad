<?php

declare(strict_types=1);

namespace App\Exceptions\Payments;

use RuntimeException;

/**
 * A payment / refund / allocation request breaks a domain rule (validation,
 * lifecycle or a limit). Nothing was written. `code` is a stable machine
 * reason for the UI and tests.
 */
final class PaymentRuleException extends RuntimeException
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
