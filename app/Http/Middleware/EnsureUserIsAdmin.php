<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authorizes access to the operator dashboard. Assumes the "auth" middleware
 * ran first (a guest is redirected to login there); an authenticated user who
 * may not access the dashboard is refused with 403.
 *
 * Since Phase C0 a user may enter either through the legacy `is_admin` flag
 * or through a role that grants `dashboard.access` (see User::canAccessDashboard).
 * Individual pages add their own permission gates on top.
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user !== null && $user->canAccessDashboard(), 403);

        return $next($request);
    }
}
