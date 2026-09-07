<?php

declare(strict_types=1);

namespace App\Support\Tools;

use App\Enums\ToolCapability;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;

/**
 * Who may change a subscriber's capability consent (Phase F1).
 *
 * GRANT and REVOKE are NOT the same decision and do not share a check:
 *
 *   GRANT   — the authenticated SUBSCRIBER THEMSELF, and no one else. No
 *             operator permission grants consent on someone's behalf, no
 *             console path grants it, and no job, provider or model can:
 *             consent is authority the subscriber creates, so it can only come
 *             from their own authenticated action. A capability with no
 *             consent stays NOT GRANTED and the platform asks them for it.
 *
 *   REVOKE  — the subscriber themself, an operator holding the capability's
 *             allowlisted permission (safety and compliance), or a console run
 *             that has EXPLICITLY declared itself an administrator through
 *             `asConsoleAdministrator()`. Staff may reduce authority; they can
 *             never create it.
 *
 * The console path is deliberately not an anonymous bypass: it exists only
 * inside that scope, it applies to revocation only, and it is audited as
 * `console_admin:<ref>` — a named administrative actor, not `console`.
 */
final class ToolAuthorization
{
    /** The administrative reference of the console scope currently in effect, if any. */
    private static ?string $administrator = null;

    /**
     * Run a console REVOCATION as a named trusted administrator. Outside this
     * scope an unauthenticated console run may not touch consent at all, and
     * inside it a grant is still refused.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function asConsoleAdministrator(string $reference, Closure $callback): mixed
    {
        if (! app()->runningInConsole()) {
            throw new AuthorizationException('The console administrator scope is available in the console only.');
        }

        $reference = trim($reference);

        if (preg_match('/^[a-z0-9][a-z0-9_.\-:]{2,47}$/', $reference) !== 1) {
            throw new AuthorizationException('A console administrator must be named with a bounded reference (e.g. ops.consent-revocation).');
        }

        $previous = self::$administrator;
        self::$administrator = $reference;

        try {
            return $callback();
        } finally {
            self::$administrator = $previous;
        }
    }

    /** Is a named console administrator scope in effect right now? */
    public static function consoleAdministrator(): ?string
    {
        return app()->runningInConsole() ? self::$administrator : null;
    }

    /**
     * Only the subscriber themself, authenticated, may grant.
     *
     * @throws AuthorizationException
     */
    public static function assertMayGrantConsent(int $subscriberId, ToolCapability $capability): void
    {
        $actor = Auth::id();

        if ($actor !== null && (int) $actor === $subscriberId) {
            return;
        }

        throw new AuthorizationException(
            "Consent for capability [{$capability->value}] can be granted only by the subscriber themself; "
            .'no operator permission, console run, job or provider may grant it on their behalf.'
        );
    }

    /**
     * The subscriber themself, an operator with the capability's allowlisted
     * permission, or a named console administrator.
     *
     * @throws AuthorizationException
     */
    public static function assertMayRevokeConsent(int $subscriberId, ToolCapability $capability): void
    {
        $user = Auth::user();

        if ($user !== null) {
            if ((int) $user->getAuthIdentifier() === $subscriberId) {
                return; // the subscriber withdraws their own consent
            }

            if ($user->can($capability->operatorPermission()->value)) {
                return; // an operator reducing authority for safety or compliance
            }

            throw new AuthorizationException("Missing permission [{$capability->operatorPermission()->value}] to revoke consent for capability [{$capability->value}].");
        }

        if (self::consoleAdministrator() === null) {
            throw new AuthorizationException("Unauthenticated revocation of capability [{$capability->value}]: a console run must declare itself an administrator first.");
        }
    }

    /**
     * The actor recorded on the row and in the audit: `user:<id>` for an
     * authenticated actor, `console_admin:<ref>` for a declared administrator.
     * Never a name, an email or an anonymous `console`.
     */
    public static function actorRef(): string
    {
        $id = Auth::id();

        if ($id !== null) {
            return 'user:'.$id;
        }

        $administrator = self::consoleAdministrator();

        return $administrator !== null ? 'console_admin:'.$administrator : 'system';
    }
}
