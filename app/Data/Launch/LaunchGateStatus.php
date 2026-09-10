<?php

declare(strict_types=1);

namespace App\Data\Launch;

use App\Enums\LaunchGateState;
use App\Support\Launch\LaunchGate;

/**
 * A gate paired with what its check answered.
 *
 * `blocksLaunch()` is decided HERE and nowhere else, from two independent facts:
 * whether V1 requires the gate (the registry's answer) and whether the state is
 * one we can prove is unfinished (the state's answer). A check cannot promote
 * itself to a blocker, and a gate cannot be blocked by a state that merely means
 * "we could not tell".
 */
final readonly class LaunchGateStatus
{
    public function __construct(
        public LaunchGate $gate,
        public LaunchGateState $state,
        public string $summary,
        /** @var list<GateDetail> */
        public array $details = [],
    ) {}

    public static function from(LaunchGate $gate, GateOutcome $outcome): self
    {
        return new self($gate, $outcome->state, $outcome->summary, $outcome->details);
    }

    public function blocksLaunch(): bool
    {
        return $this->gate->requiredForV1 && $this->state->blocksLaunch();
    }

    /** Shown with a "not a blocker" tag so a red badge on a deferred item cannot mislead. */
    public function isDeferred(): bool
    {
        return ! $this->gate->requiredForV1;
    }
}
