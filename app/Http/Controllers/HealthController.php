<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Liveness/readiness endpoint for SANAD.
 *
 * Returns a small, safe JSON payload describing the health of the
 * application and its core dependencies. It intentionally exposes NO
 * secrets, credentials, hostnames, or configuration values.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $database = $this->checkDatabase();
        $redis = $this->checkRedis();

        $healthy = $database === 'ok' && $redis === 'ok';

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'app' => config('sanad.name', 'SANAD'),
            'environment' => app()->environment(),
            'services' => [
                'postgres' => $database,
                'redis' => $redis,
            ],
            'timestamp' => now()->toIso8601String(),
        ], $healthy ? 200 : 503);
    }

    /**
     * Ping the database. Never throws, never leaks connection details.
     */
    private function checkDatabase(): string
    {
        try {
            DB::connection()->getPdo();
            DB::connection()->select('select 1');

            return 'ok';
        } catch (Throwable) {
            return 'down';
        }
    }

    /**
     * Ping Redis. Never throws, never leaks connection details.
     */
    private function checkRedis(): string
    {
        try {
            $response = Redis::connection()->ping();

            // phpredis returns true/"+PONG"/"PONG" depending on version.
            $ok = $response === true
                || $response === 'PONG'
                || $response === '+PONG';

            return $ok ? 'ok' : 'down';
        } catch (Throwable) {
            return 'down';
        }
    }
}
