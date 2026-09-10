<?php

declare(strict_types=1);

namespace App\Data\Launch;

use App\Enums\LaunchGateState;

/**
 * What a check answered, before it is paired with the gate it answered for.
 *
 * A check knows the state and can explain it; only the REGISTRY knows whether
 * the gate is required for V1, and only `LaunchReadiness` turns the pair into a
 * blocker or not. Keeping the two apart is what stops a check from quietly
 * deciding it is a launch blocker.
 */
final readonly class GateOutcome
{
    /**
     * @param  list<GateDetail>  $details
     */
    private function __construct(
        public LaunchGateState $state,
        public string $summary,
        public array $details = [],
    ) {}

    /**
     * @param  list<GateDetail>  $details
     */
    public static function of(LaunchGateState $state, string $summary, array $details = []): self
    {
        return new self($state, $summary, $details);
    }

    /**
     * @param  list<GateDetail>  $details
     */
    public static function ready(string $summary, array $details = []): self
    {
        return new self(LaunchGateState::Ready, $summary, $details);
    }

    /**
     * @param  list<GateDetail>  $details
     */
    public static function notReady(string $summary, array $details = []): self
    {
        return new self(LaunchGateState::NotReady, $summary, $details);
    }

    /**
     * @param  list<GateDetail>  $details
     */
    public static function notImplemented(string $summary, array $details = []): self
    {
        return new self(LaunchGateState::NotImplemented, $summary, $details);
    }

    /**
     * @param  list<GateDetail>  $details
     */
    public static function blockedExternal(string $summary, array $details = []): self
    {
        return new self(LaunchGateState::BlockedExternal, $summary, $details);
    }

    /**
     * @param  list<GateDetail>  $details
     */
    public static function notObserved(string $summary, array $details = []): self
    {
        return new self(LaunchGateState::NotObserved, $summary, $details);
    }
}
