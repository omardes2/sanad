<?php

declare(strict_types=1);

namespace App\Support\Payments;

use App\Support\Rbac\Permission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;

/**
 * Server-side re-check for every financial WRITE (Phase E1): the acting user
 * must hold the permission; a request without a user is refused. Console
 * processes without a user (commands, test probes) are system actors and pass —
 * the same actor model AuditLogger uses.
 */
final class FinanceAuthorization
{
    /**
     * @throws AuthorizationException
     */
    public static function assertCan(Permission $permission): void
    {
        $user = Auth::user();

        if ($user !== null) {
            if (! $user->can($permission->value)) {
                throw new AuthorizationException("Missing permission [{$permission->value}].");
            }

            return;
        }

        if (! app()->runningInConsole()) {
            throw new AuthorizationException('Unauthenticated financial write refused.');
        }
    }

    public static function actorRef(): string
    {
        $user = Auth::user();

        if ($user !== null) {
            return 'user:'.$user->getAuthIdentifier();
        }

        return app()->runningInConsole() ? 'console' : 'system';
    }
}
