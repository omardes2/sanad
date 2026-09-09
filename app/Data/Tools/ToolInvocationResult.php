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
        /**
         * The FULL declared output of an execution that just happened, held for
         * this turn only. It exists because a tool may be allowed to tell the
         * model more than it is allowed to store: `memory.read@2` returns the
         * subscriber's memories, and `ToolOutputPersistence` keeps only their
         * shape on the row. Null for every tool that stores its whole output,
         * and null on a replay — a replay legitimately sees only what was kept.
         *
         * @var array<string, mixed>|null
         */
        public ?array $transientOutput = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $transientOutput  the full result, when this call executed one
     */
    public static function settled(ToolClaimOutcome $claim, ToolInvocation $invocation, bool $executed, ?array $transientOutput = null): self
    {
        return new self($claim, $invocation, $invocation->status, $invocation->refusal_reason, $executed, $transientOutput);
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

    /**
     * The declared output of a successful invocation, replay included.
     *
     * The transient result of THIS execution wins when there is one, because a
     * redacted row deliberately holds less than the model was allowed to see.
     * Falling back to the stored projection is what makes a replay honest
     * rather than empty.
     */
    public function output(): ?array
    {
        if (! $this->succeeded()) {
            return null;
        }

        return $this->transientOutput ?? $this->invocation?->output ?? [];
    }
}
