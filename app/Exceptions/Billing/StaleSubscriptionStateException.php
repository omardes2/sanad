<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use RuntimeException;

/**
 * The subscription state the admin acted on changed before the mutation ran
 * (another admin won the race). Nothing was written — no state, no event, no
 * audit; the admin must refresh and decide again.
 */
final class StaleSubscriptionStateException extends RuntimeException
{
    public static function forToken(string $expected, string $current): self
    {
        return new self("Subscription state changed since it was viewed (expected token [{$expected}], current [{$current}]). Nothing was written; refresh and retry.");
    }
}
