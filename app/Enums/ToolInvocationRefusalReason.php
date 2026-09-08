<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why an invocation was refused BEFORE anything ran (Phase F2).
 *
 * Only the first two ever reach a stored row: they are decided after the claim
 * and are recorded as `planned → refused` / `authorized → refused`. The rest
 * happen before an invocation can legitimately be claimed, so they are
 * returned to the caller and never persisted as a malformed row.
 */
enum ToolInvocationRefusalReason: string
{
    /** The subscriber never granted the capability (no row = NOT GRANTED). */
    case NotGranted = 'not_granted';

    /** Consent was withdrawn between authorization and execution. */
    case ConsentRevoked = 'consent_revoked';

    case UnknownTool = 'unknown_tool';

    case UnknownVersion = 'unknown_version';

    case InvalidInput = 'invalid_input';

    /** F2 executes `read` tools only; anything else is not executable yet. */
    case SideEffectNotExecutable = 'side_effect_not_executable';

    case SubscriberMissing = 'subscriber_missing';

    /** Is this reason decided after a claim, and therefore recorded on the invocation? */
    public function isPersisted(): bool
    {
        return $this === self::NotGranted || $this === self::ConsentRevoked;
    }
}
