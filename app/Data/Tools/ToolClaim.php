<?php

declare(strict_types=1);

namespace App\Data\Tools;

use App\Enums\ToolClaimOutcome;
use App\Models\ToolInvocation;

/**
 * The result of claiming one invocation identity (Phase F2).
 *
 * Exactly one of the four outcomes, and only `claimed` wrote anything. The
 * other three are OBSERVATIONS of a row that already exists: they leave its
 * status untouched, append no event, write no audit, write no usage row and
 * execute nothing — and they never block waiting for another process.
 */
final readonly class ToolClaim
{
    private function __construct(public ToolClaimOutcome $outcome, public ToolInvocation $invocation) {}

    public static function claimed(ToolInvocation $invocation): self
    {
        return new self(ToolClaimOutcome::Claimed, $invocation);
    }

    /** The same key and the same canonical input already reached a terminal state. */
    public static function replay(ToolInvocation $invocation): self
    {
        return new self(ToolClaimOutcome::Replay, $invocation);
    }

    /** The same key and the same canonical input is being executed right now, elsewhere. */
    public static function inFlight(ToolInvocation $invocation): self
    {
        return new self(ToolClaimOutcome::InFlight, $invocation);
    }

    /** The same key was already used for a DIFFERENT canonical input; the stored one stays authoritative. */
    public static function conflict(ToolInvocation $invocation): self
    {
        return new self(ToolClaimOutcome::Conflict, $invocation);
    }

    public function isClaimed(): bool
    {
        return $this->outcome === ToolClaimOutcome::Claimed;
    }
}
