<?php

declare(strict_types=1);

namespace App\Services\Settings;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Short cache (30 s) for the stored settings map, shared by every web and
 * queue worker through the application cache store. Entries are namespaced
 * by a version number that every write bumps, so a change is visible to all
 * workers immediately after the write and within the TTL at the latest — no
 * worker restart, no `config:cache` involved.
 *
 * The cache is an optimisation, never a dependency: if the store is down the
 * callback runs directly and the message pipeline keeps working.
 */
final class SettingsCache
{
    public const TTL_SECONDS = 30;

    private const VERSION_KEY = 'settings.version';

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function remember(string $key, Closure $callback): mixed
    {
        try {
            return Cache::remember('settings.v'.$this->version().'.'.$key, self::TTL_SECONDS, $callback);
        } catch (Throwable $e) {
            Log::warning('sanad.settings.cache_unavailable', ['error' => $e::class]);

            return $callback();
        }
    }

    public function flush(): void
    {
        try {
            Cache::forever(self::VERSION_KEY, $this->version() + 1);
        } catch (Throwable $e) {
            Log::warning('sanad.settings.cache_unavailable', ['error' => $e::class]);
        }
    }

    private function version(): int
    {
        try {
            return (int) Cache::get(self::VERSION_KEY, 1);
        } catch (Throwable) {
            return 1;
        }
    }
}
