<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authorizes access to the operator dashboard. Assumes the "auth" middleware
 * ran first (a guest is redirected to login there); an authenticated but
 * non-admin user is refused with 403. No dashboard route is reachable without
 * both middleware in place.
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user !== null && $user->isAdmin(), 403);

        return $next($request);
    }
}
