<?php

declare(strict_types=1);

namespace App\Services\Ai\Catalog;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Short-lived cache for the database-backed catalog and model resolution.
 * Entries are namespaced by a version number; flush() bumps the version, so
 * every entry is invalidated at once without cache tags (the models bump it
 * on save/delete, as do the artisan commands) — always AFTER the outermost
 * commit, see flushAfterCommit().
 *
 * The cache is an optimisation, never a dependency: if the cache store is
 * unreachable, callbacks run directly (uncached) and routing keeps working —
 * B2 must not add a new failure mode to the message pipeline.
 */
final class CatalogCache
{
    private const VERSION_KEY = 'ai.catalog.version';

    public const TTL_SECONDS = 60;

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function remember(string $key, Closure $callback): mixed
    {
        try {
            return Cache::remember(self::key($key), self::TTL_SECONDS, $callback);
        } catch (Throwable $e) {
            Log::warning('sanad.ai.catalog_cache_unavailable', ['error' => $e::class]);

            return $callback();
        }
    }

    /**
     * Invalidate only once the OUTERMOST database transaction has really
     * committed (Phase C2 decision 5): inside a transaction the flush is
     * deferred to the root commit and discarded on rollback, so no worker
     * ever sees an invalidation for a change that was not (or not yet)
     * committed. Outside a transaction it flushes immediately.
     */
    public static function flushAfterCommit(): void
    {
        try {
            DB::afterCommit(static fn () => self::flush());
        } catch (Throwable $e) {
            Log::warning('sanad.ai.catalog_cache_unavailable', ['error' => $e::class]);
        }
    }

    public static function flush(): void
    {
        try {
            Cache::forever(self::VERSION_KEY, self::version() + 1);
        } catch (Throwable $e) {
            Log::warning('sanad.ai.catalog_cache_unavailable', ['error' => $e::class]);
        }
    }

    public static function key(string $key): string
    {
        return 'ai.catalog.v'.self::version().'.'.$key;
    }

    private static function version(): int
    {
        try {
            return (int) Cache::get(self::VERSION_KEY, 1);
        } catch (Throwable) {
            return 1;
        }
    }
}
