<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to local/testing environments. In any other environment
 * (production included) the route responds 404 — the developer tools simply
 * do not exist there.
 */
class EnsureDevEnvironment
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(app()->environment(['local', 'testing']), 404);

        return $next($request);
    }
}
