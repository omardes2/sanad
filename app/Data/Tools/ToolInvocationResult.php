<?php

declare(strict_types=1);

namespace App\Data\Tools;

use App\Enums\ToolClaimOutcome;
use App\Enums\ToolInvocationRefusalReason;
use App\Enums\ToolInvocationStatus;
use App\Enums\ToolReplayFailure;
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
        /**
         * Set only on a REPLAY of a tool whose result is not stored in full, when
         * that result could not be re-derived. The replayed invocation still
         * SUCCEEDED — nothing about it changes — but this call has no semantic
         * result to offer, and the stored projection is not one.
         */
        public ?ToolReplayFailure $replayFailure = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $transientOutput  the full result, when this call produced one
     */
    public static function settled(
        ToolClaimOutcome $claim,
        ToolInvocation $invocation,
        bool $executed,
        ?array $transientOutput = null,
        ?ToolReplayFailure $replayFailure = null,
    ): self {
        return new self($claim, $invocation, $invocation->status, $invocation->refusal_reason, $executed, $transientOutput, $replayFailure);
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
     * The declared output of a successful invocation, replay included — or NULL
     * when this call has no semantic result to give.
     *
     * The transient result of THIS call wins when there is one. When a replay
     * could not re-derive a redacted result there is NO result: the projection on
     * the row is audit metadata, not an answer, and returning it would claim the
     * tool succeeded in producing something it did not produce. Callers read
     * `replayFailure` and report the bounded code instead.
     */
    public function output(): ?array
    {
        if (! $this->succeeded() || $this->replayFailure !== null) {
            return null;
        }

        return $this->transientOutput ?? $this->invocation?->output ?? [];
    }

    /** Did this call end without a usable result even though the invocation succeeded? */
    public function rehydrationFailed(): bool
    {
        return $this->replayFailure !== null;
    }
}
