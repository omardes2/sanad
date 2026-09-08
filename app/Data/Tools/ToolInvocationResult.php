<?php

declare(strict_types=1);

namespace App\Data\Tools;

use App\Enums\ToolClaimOutcome;
use App\Enums\ToolInvocationRefusalReason;
use App\Enums\ToolInvocationStatus;
use App\Models\ToolInvocation;

/**
 * What one call to the executor produced (Phase F2).
 *
 * `invocation` is null only for a refusal decided BEFORE a claim could be made
 * (an unknown tool or version, an input the schema refuses, a side-effect class
 * F2 does not execute): those never create a malformed row just to be logged.
 */
final readonly class ToolInvocationResult
{
    private function __construct(
        /** Null when the call was refused before any claim could be attempted. */
        public ?ToolClaimOutcome $claim,
        public ?ToolInvocation $invocation,
        public ToolInvocationStatus $status,
        public ?ToolInvocationRefusalReason $refusal = null,
        public bool $executed = false,
    ) {}

    public static function settled(ToolClaimOutcome $claim, ToolInvocation $invocation, bool $executed): self
    {
        return new self($claim, $invocation, $invocation->status, $invocation->refusal_reason, $executed);
    }

    /** Refused before a claim: nothing stored, nothing executed. */
    public static function refusedBeforeClaim(ToolInvocationRefusalReason $reason): self
    {
        return new self(null, null, ToolInvocationStatus::Refused, $reason);
    }

    public function succeeded(): bool
    {
        return $this->status === ToolInvocationStatus::Succeeded;
    }

    /** The declared output of a successful invocation, replay included. */
    public function output(): ?array
    {
        return $this->succeeded() ? ($this->invocation?->output ?? []) : null;
    }
}
