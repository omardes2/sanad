<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Permission gate for dashboard pages that PRE-DATE RBAC (Phase C0).
 *
 * A legacy `is_admin` account keeps its access to those pages exactly as
 * before (backward compatibility), while role-based accounts need the named
 * permission. Pages introduced with or after RBAC must NOT use this: they use
 * spatie's strict `permission:` middleware, so a legacy admin without a role
 * is refused until `sanad:rbac:bootstrap --promote-admins` grants one.
 *
 * Usage: ->middleware('permission.legacy:plans.manage')
 */
class EnsureLegacyAdminOrPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        abort_if($user === null, 403);

        if ($user->isAdmin() || $user->can($permission)) {
            return $next($request);
        }

        abort(403);
    }
}
