<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use RuntimeException;

/**
 * The plan's open price version changed since the admin loaded the form
 * (another financial edit won the race). Nothing was written — no plan change,
 * no version closed or opened, no audit; refresh and retry with the new version.
 */
final class StalePlanPriceVersionException extends RuntimeException
{
    public static function forVersion(?int $expected, ?int $current): self
    {
        return new self('Plan price version changed since the form was loaded (expected open version ['.($expected ?? 'none').'], current ['.($current ?? 'none').']). Nothing was written; refresh and retry.');
    }
}
