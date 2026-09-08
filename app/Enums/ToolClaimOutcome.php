<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a claim on one invocation identity produced (Phase F2). None of these
 * is a stored status: `claimed` created the row, and the other three observed
 * an existing one WITHOUT mutating anything — no transition, no event, no
 * audit, no usage, no second execution.
 */
enum ToolClaimOutcome: string
{
    /** This process created the invocation and owns its execution. */
    case Claimed = 'claimed';

    /** The same key and the same canonical input already finished: the recorded result stands. */
    case Replay = 'replay';

    /** The same key and the same canonical input is still being executed elsewhere. */
    case InFlight = 'in_flight';

    /** The same key was already used for a DIFFERENT canonical input. The stored one stays authoritative. */
    case Conflict = 'conflict';
}
