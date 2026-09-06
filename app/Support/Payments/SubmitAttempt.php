<?php

declare(strict_types=1);

namespace App\Support\Payments;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * UI-level duplicate-submit guard (Phase E5.2a). One attempt key per form
 * attempt (generated when the attempt starts, kept across retries, rotated
 * only after success): the first submit of a key claims it, a concurrent
 * double-click loses the claim and is refused before any service runs. A
 * refused / stale attempt releases the claim so the SAME key can be
 * resubmitted by the user on purpose.
 *
 * What this is and is not:
 *  - backend: the application's default cache store (`CACHE_STORE`; redis in
 *    production, database as the framework default). Redis `add` is an atomic
 *    Lua SET-NX-with-TTL; the database store inserts the key under its primary
 *    key (atomic); the `array` / `file` stores are per-process and non-atomic —
 *    they only ever guard a single process;
 *  - not durable: the claim lives for TTL_SECONDS and disappears on a cache
 *    flush / restart; it carries no payload fingerprint (key only);
 *  - fail-closed: if the store is unreachable the claim is refused and the
 *    submit is stopped before any service runs (nothing is written; the user
 *    retries the same attempt key).
 * The financial truth is the services' idempotency keys (payment, refund AND
 * — since E5.2a — payment / refund allocation, each backed by a database
 * unique index), state tokens, row locks and caps. This guard is UX only:
 * financial correctness never depends on the cache store.
 */
final class SubmitAttempt
{
    public const TTL_SECONDS = 600;

    public static function claim(string $scope, string $key): bool
    {
        try {
            return Cache::add(self::cacheKey($scope, $key), 1, self::TTL_SECONDS);
        } catch (Throwable) {
            return false; // store unavailable ⇒ refuse the submit (fail-closed), never proceed unguarded
        }
    }

    public static function release(string $scope, string $key): void
    {
        try {
            Cache::forget(self::cacheKey($scope, $key));
        } catch (Throwable) {
            // a claim that cannot be released simply expires with its TTL
        }
    }

    /** The store the claim is written to — for diagnostics and tests. */
    public static function backend(): string
    {
        return (string) config('cache.default');
    }

    private static function cacheKey(string $scope, string $key): string
    {
        return 'submit-attempt:'.$scope.':'.hash('sha256', $key);
    }
}
