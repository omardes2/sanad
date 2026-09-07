<?php

declare(strict_types=1);

namespace App\Support\Tools;

use App\Enums\ToolCapability;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;

/**
 * Who may administer a subscriber's capability consent (Phase F1).
 *
 * The two gates of the capability model are INDEPENDENT and this class is the
 * OPERATOR one only. Holding the permission lets someone grant or revoke a
 * subscriber's consent; it never gives the operator that consent, and a
 * subscriber's consent never gives anyone a permission.
 *
 * Allowed: the subscriber themself (self-service), or a user holding the
 * capability's operator permission — which comes from a code allowlist on the
 * enum, never from a string. Unauthenticated is allowed only in the console
 * (operator CLI and the test probes), exactly as the settings writer does, and
 * is audited as `console`.
 */
final class ToolAuthorization
{
    /**
     * @throws AuthorizationException
     */
    public static function assertMayManageConsent(int $subscriberId, ToolCapability $capability): void
    {
        $user = Auth::user();

        if ($user !== null) {
            if ((int) $user->getAuthIdentifier() === $subscriberId) {
                return; // the subscriber decides for themself
            }

            if ($user->can($capability->operatorPermission()->value)) {
                return; // an operator acting on their behalf
            }

            throw new AuthorizationException("Missing permission [{$capability->operatorPermission()->value}] to manage consent for capability [{$capability->value}].");
        }

        if (! app()->runningInConsole()) {
            throw new AuthorizationException("Unauthenticated consent change for capability [{$capability->value}].");
        }
    }

    /** `user:<id>` for an authenticated actor, `console` for a CLI run — never a name or an email. */
    public static function actorRef(): string
    {
        $id = Auth::id();

        return $id !== null ? 'user:'.$id : (app()->runningInConsole() ? 'console' : 'system');
    }
}
