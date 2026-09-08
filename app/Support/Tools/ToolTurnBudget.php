<?php

declare(strict_types=1);

namespace App\Support\Tools;

/**
 * The turn budget for ONE inbound user message (Phase F4). The server owns
 * every counter; nothing a provider or a model returns can raise or reset one.
 *
 * Per inbound user message, at most:
 *   - 3 provider model calls
 *   - 2 tool-execution rounds
 *   - 3 tool invocations in total
 *
 * The shape of a turn is therefore fixed and finite:
 *   call 1  the user's turn — the model answers, or proposes tools
 *   call 2  sees round-1 results — answers, or proposes the second and LAST round
 *   call 3  final synthesis only, with tool calling DISABLED
 * There is no fourth call and no third round, so there is no unbounded loop.
 *
 * A batch is all-or-nothing: a proposed batch is executed only if the WHOLE of
 * it fits the remaining invocation budget. An oversized batch is refused
 * entirely — never partially — and the turn continues to a final call with
 * tools disabled and the reason `tool_budget_exceeded`, so the model can answer
 * honestly instead of pretending the tools ran.
 */
final class ToolTurnBudget
{
    public const MAX_PROVIDER_CALLS = 3;

    public const MAX_TOOL_ROUNDS = 2;

    public const MAX_TOOL_INVOCATIONS = 3;

    /** The bounded, machine-readable reason a refused batch reports. */
    public const EXCEEDED = 'tool_budget_exceeded';

    private int $providerCalls = 0;

    private int $toolRounds = 0;

    private int $toolInvocations = 0;

    public function providerCalls(): int
    {
        return $this->providerCalls;
    }

    public function toolRounds(): int
    {
        return $this->toolRounds;
    }

    public function toolInvocations(): int
    {
        return $this->toolInvocations;
    }

    public function mayCallProvider(): bool
    {
        return $this->providerCalls < self::MAX_PROVIDER_CALLS;
    }

    /** The provider call the server is about to make; returns its 1-based index. */
    public function countProviderCall(): int
    {
        return ++$this->providerCalls;
    }

    /** May tools be OFFERED on the call that is about to be made? */
    public function mayOfferTools(): bool
    {
        return $this->toolRounds < self::MAX_TOOL_ROUNDS
            && $this->toolInvocations < self::MAX_TOOL_INVOCATIONS
            // The last permitted provider call is always final synthesis.
            && $this->providerCalls < self::MAX_PROVIDER_CALLS - 1;
    }

    public function remainingInvocations(): int
    {
        return max(0, self::MAX_TOOL_INVOCATIONS - $this->toolInvocations);
    }

    /** A whole batch fits, or it does not run at all. */
    public function fits(int $batchSize): bool
    {
        return $batchSize > 0
            && $this->toolRounds < self::MAX_TOOL_ROUNDS
            && $batchSize <= $this->remainingInvocations();
    }

    /** Record one executed round. Called only after `fits()` allowed it. */
    public function countRound(int $batchSize): void
    {
        $this->toolRounds++;
        $this->toolInvocations += $batchSize;
    }
}
