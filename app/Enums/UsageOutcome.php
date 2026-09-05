<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Result of a usage check/charge decision.
 */
enum UsageOutcome: string
{
    case Allowed = 'allowed';
    case LimitReached = 'limit_reached';
    case Disabled = 'disabled';          // plan does not include this dimension
    case NoSubscription = 'no_subscription';
    case AlreadyCharged = 'already_charged'; // idempotent replay — treat as allowed
    case NotEnforced = 'not_enforced';   // billing.enforce is off
}
