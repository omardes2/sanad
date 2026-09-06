<?php

declare(strict_types=1);

namespace App\Support\Payments;

use Illuminate\Support\Facades\Cache;

/**
 * UI-level duplicate-submit guard (Phase E5.2a). One attempt key per form
 * attempt (generated when the attempt starts, kept across retries, rotated
 * only after success): the first submit of a key claims it atomically
 * (Cache::add), a concurrent double-click loses the claim and is refused
 * before any service runs. A refused / stale attempt releases the claim so
 * the SAME key can be resubmitted by the user on purpose.
 *
 * This is convenience, not financial truth: the real protection stays the
 * services' idempotency keys, state tokens, row locks and caps.
 */
final class SubmitAttempt
{
    public const TTL_SECONDS = 600;

    public static function claim(string $scope, string $key): bool
    {
        return Cache::add(self::cacheKey($scope, $key), 1, self::TTL_SECONDS);
    }

    public static function release(string $scope, string $key): void
    {
        Cache::forget(self::cacheKey($scope, $key));
    }

    private static function cacheKey(string $scope, string $key): string
    {
        return 'submit-attempt:'.$scope.':'.hash('sha256', $key);
    }
}
