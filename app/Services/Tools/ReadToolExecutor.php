<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Data\Tools\ToolCallRequest;
use App\Data\Tools\ToolClaim;
use App\Data\Tools\ToolInvocationResult;
use App\Enums\ToolClaimOutcome;
use App\Enums\ToolInvocationFailureKind;
use App\Enums\ToolInvocationRefusalReason;
use App\Enums\ToolInvocationStatus;
use App\Enums\ToolReplayFailure;
use App\Enums\ToolSideEffect;
use App\Exceptions\Tools\ToolDefinitionException;
use App\Exceptions\Tools\ToolRuleException;
use App\Models\Message;
use App\Models\ToolInvocation;
use App\Models\User;
use App\Services\Memory\MemoryService;
use App\Services\Reminders\ReminderScheduleService;
use App\Services\Tasks\TaskReader;
use App\Support\Tools\ReadOnlyQueryGuard;
use App\Support\Tools\ToolCallPlan;
use App\Support\Tools\ToolOutputPersistence;
use Throwable;

/**
 * Executes SYNCHRONOUS READ tools, and nothing else (Phase F2).
 *
 * The handler of a tool is resolved from the CODE ALLOWLIST below, keyed by
 * `name@version`. Nothing is ever resolved from registry metadata, from the
 * database or from a payload: a definition still cannot name a class, and this
 * map is the only place that says what actually runs.
 *
 * The read-only boundary and the identity of a call are SEPARATE contracts and
 * are answered in that order. A candidate whose side-effect class is not `read`
 * is refused before an invocation is claimed at all — `side_effect_not_executable`
 * — even when the slot it names is already owned and the store would have said
 * CONFLICT; nothing is created, nothing existing is touched, nothing runs. A
 * `read` candidate does reach the claim, so an owned slot answers on identity:
 * a different read tool, or a different version of the same one, is a CONFLICT.
 *
 * What F2 guarantees, stated precisely:
 *   - EXACTLY ONCE for the invocation claim and for the terminal record
 *     (status, event, audit, usage) — the unique identity and the fact that a
 *     terminal state accepts no transition are what make that true;
 *   - AT MOST ONCE for the execution itself under normal process execution.
 * It is NOT distributed exactly-once. If the process dies after the read ran
 * but before the terminal transaction committed, Sanad cannot know whether the
 * read completed: the invocation stays `running`, the stale-running sweeper
 * later settles it as `timed_out`, and nothing re-executes it under the same
 * identity. That is deliberate, and it is only acceptable because the work was
 * a read — the same policy must never be inherited by an external write.
 */
final class ReadToolExecutor
{
    /**
     * The code allowlist: `name@version` ⇒ [handler class, method].
     *
     * @var array<string, array{0: class-string, 1: string}>
     */
    private const HANDLERS = [
        // `memory.read@1` is FROZEN in meaning — how many memories match — and
        // now answers it over encrypted rows, in application memory. `@2` is
        // the version that returns the memories themselves.
        'memory.read@1' => [MemoryService::class, 'count'],
        'memory.read@2' => [MemoryService::class, 'recall'],
        'task.list@1' => [TaskReader::class, 'read'],
        // Discovery, not content: which recurring series the subscriber owns, so
        // the model can tell which one they mean days after creating it.
        'reminder_schedule.list@1' => [ReminderScheduleService::class, 'list'],
    ];

    public function __construct(
        private readonly ToolCallPlan $plan,
        private readonly ToolInvocationStore $store,
        private readonly ToolConsentService $consents,
        private readonly ReadOnlyQueryGuard $guard,
    ) {}

    /** @return list<string> the keys F2 can actually execute */
    public static function executableKeys(): array
    {
        return array_keys(self::HANDLERS);
    }

    /**
     * The production entry point for one call of one stored message: derive the
     * identity from the plan, then execute. An unknown tool, an unknown version
     * or an input the closed schema refuses is decided HERE, before a claim, so
     * no malformed invocation row is ever created just to record a rejection.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function call(Message $message, string $key, array $arguments): ToolInvocationResult
    {
        try {
            $request = $this->plan->one($message, $key, $arguments);
        } catch (ToolDefinitionException) {
            return ToolInvocationResult::refusedBeforeClaim(ToolInvocationRefusalReason::UnknownTool);
        } catch (ToolRuleException $e) {
            return ToolInvocationResult::refusedBeforeClaim(
                $e->rule === 'subscriber' ? ToolInvocationRefusalReason::SubscriberMissing : ToolInvocationRefusalReason::InvalidInput
            );
        }

        return $this->execute($request);
    }

    public function execute(ToolCallRequest $request): ToolInvocationResult
    {
        // TWO SEPARATE CONTRACTS, in this order, and never conflated:
        //
        // 1. F2 IS READ-ONLY. A candidate whose side-effect class is not `read`
        //    fails closed here, BEFORE any claim: no row is created, an
        //    invocation that already owns the slot is not read or touched, and
        //    nothing executes. This holds even where the store would have
        //    answered CONFLICT — the read-only boundary is decided first.
        if ($request->definition->sideEffect !== ToolSideEffect::Read) {
            return ToolInvocationResult::refusedBeforeClaim(ToolInvocationRefusalReason::SideEffectNotExecutable);
        }

        if (! User::query()->whereKey($request->subscriber->getKey())->exists()) {
            return ToolInvocationResult::refusedBeforeClaim(ToolInvocationRefusalReason::SubscriberMissing);
        }

        // 2. IDENTITY. A read candidate goes to the claim, so a slot that is
        //    already owned answers on identity alone — a different read tool or
        //    a different version of one is a CONFLICT, not a read-only refusal.
        //    Replay, in flight and conflict all changed nothing and execute
        //    nothing; none of them needs a handler.
        $claim = $this->store->claim($request);

        if (! $claim->isClaimed()) {
            [$rehydrated, $rehydrationFailure] = $this->rehydrate($claim, $request);

            return ToolInvocationResult::settled(
                $claim->outcome,
                $claim->invocation,
                executed: false,
                transientOutput: $rehydrated,
                replayFailure: $rehydrationFailure,
            );
        }

        $invocation = $claim->invocation;

        // A `read` the code allowlist cannot run is a wiring error, not a
        // caller error: a test pins that every shipped read has a handler, so
        // this is unreachable in production. If it ever happened, the slot it
        // just claimed is refused terminally — never left dangling, and never
        // executed.
        if (! array_key_exists($request->toolKey(), self::HANDLERS)) {
            return $this->settledResult($claim, $this->store->refuse($invocation, ToolInvocationRefusalReason::SideEffectNotExecutable), executed: false);
        }
        $capability = $request->definition->capability;
        $subscriberId = (int) $request->subscriber->getKey();

        // Consent gate #1 — read from the live row, never from a cached snapshot
        // and never inferred from an earlier invocation of the same tool.
        if (! $this->consents->granted($subscriberId, $capability)) {
            return $this->settledResult($claim, $this->store->refuse($invocation, ToolInvocationRefusalReason::NotGranted), executed: false);
        }

        $invocation = $this->store->authorize($invocation);

        // Consent gate #2 — re-read inside the transaction that writes `running`,
        // so a revocation committed in between refuses instead of being overtaken.
        $invocation = $this->store->begin($invocation, fn (): bool => $this->consents->granted($subscriberId, $capability));

        if ($invocation->status !== ToolInvocationStatus::Running) {
            return $this->settledResult($claim, $invocation, executed: false);
        }

        [$settled, $output] = $this->run($request, $invocation);

        // The FULL result goes back to the caller for this turn; what the row
        // kept is whatever `ToolOutputPersistence` allowed.
        return $this->settledResult($claim, $settled, executed: true, transientOutput: $output);
    }

    // ------------------------------------------------------------------

    /**
     * REPLAY OUTPUT REHYDRATION — not a second execution (Phase G).
     *
     * A tool whose result is deliberately NOT stored in full would otherwise
     * lose its answer to an infrastructure retry: the queue re-processes the
     * same inbound message, the same slot replays, and only the projection is
     * left. The subscriber's reply must not depend on whether a provider call
     * happened to fail, so the read is re-derived from live data instead.
     *
     * It runs only when ALL of these hold, and the first four are already
     * PROVEN by the claim itself — `observe()` answers CONFLICT rather than
     * REPLAY unless the tool, the version and the canonical input hash all
     * match the slot:
     *
     *   - the outcome is REPLAY of this exact invocation slot;
     *   - the recorded invocation SUCCEEDED;
     *   - the side-effect class is `read` (this executor runs nothing else, and
     *     re-deriving a write would BE a second write);
     *   - the tool declares itself rehydratable (`ToolOutputPersistence`);
     *   - CONSENT IS STILL GRANTED. It is re-read here for the same reason it is
     *     re-read before an execution: a subscriber who revoked between the two
     *     attempts must not have their data handed to a provider again.
     *
     * NOTHING is written: no invocation, no transition, no event, no audit, no
     * usage row. The stored record of the original execution stays exactly as
     * it was, and `executed` remains false.
     *
     * HONEST CAVEAT: the re-derived result reflects the CURRENT committed state.
     * If the subscriber's memories changed between the two attempts, the retry
     * sees the newer set. That is the correct trade: the alternative is either
     * a stale plaintext copy on the row, or no answer at all.
     *
     * WHEN IT CANNOT HAPPEN THERE IS NO RESULT — not a thinner one. The stored
     * projection (`{memories_count, truncated}`) is audit metadata, and handing
     * it back as a successful `memory.read@2` would claim memories were returned
     * when none were, and would disclose how many exist to a caller that may no
     * longer be allowed to know. So the failure is explicit and bounded
     * (`ToolReplayFailure`), the model is told Sanad cannot reach what it
     * remembers, and nothing is written either way.
     *
     * @return array{0: array<string, mixed>|null, 1: ToolReplayFailure|null}
     */
    private function rehydrate(ToolClaim $claim, ToolCallRequest $request): array
    {
        $definition = $request->definition;

        if ($claim->outcome !== ToolClaimOutcome::Replay
            || $claim->invocation->status !== ToolInvocationStatus::Succeeded
            || $definition->sideEffect !== ToolSideEffect::Read
            || ! ToolOutputPersistence::rehydratableOnReplay($definition->key)
            || ! array_key_exists($request->toolKey(), self::HANDLERS)) {
            // Not a rehydratable replay at all: ordinary replay semantics apply
            // and the stored output IS this tool's whole result.
            return [null, null];
        }

        if (! $this->consents->granted((int) $request->subscriber->getKey(), $definition->capability)) {
            // The same answer a first-attempt refusal gives, and it discloses
            // nothing: not the content, not the count, not the earlier output.
            return [null, ToolReplayFailure::NotGranted];
        }

        if (! $this->rehydrationIsSafe($request)) {
            return [null, ToolReplayFailure::RehydrationUnavailable];
        }

        [$class, $method] = self::HANDLERS[$request->toolKey()];

        try {
            $raw = $this->guard->run(fn (): mixed => app($class)->{$method}($request->subscriber, $request->input->values));

            return [$definition->output->validate(is_array($raw) ? $raw : []), null];
        } catch (Throwable) {
            // The reader threw, or produced something its declared schema
            // refuses. Either way this call has no answer to give.
            return [null, ToolReplayFailure::RehydrationUnavailable];
        }
    }

    /**
     * Can the underlying domain reconstruct this read completely?
     *
     * Declared per tool in code, for the same reason the handler map is: the
     * executor must not infer a domain precondition. For memory it means the
     * keys are configured AND every row in the subscriber's bounded active set
     * opens — because a replay that silently came back short would tell the model
     * a memory does not exist when the truth is that Sanad cannot read it.
     */
    private function rehydrationIsSafe(ToolCallRequest $request): bool
    {
        return match ($request->toolKey()) {
            'memory.read@2' => app(MemoryService::class)->readable($request->subscriber),
            default => true,
        };
    }

    /**
     * @return array{0: ToolInvocation, 1: array<string, mixed>|null}
     */
    private function run(ToolCallRequest $request, ToolInvocation $invocation): array
    {
        $definition = $request->definition;
        [$class, $method] = self::HANDLERS[$request->toolKey()];
        $startedAt = hrtime(true);

        try {
            $raw = $this->guard->run(fn (): mixed => app($class)->{$method}($request->subscriber, $request->input->values));
        } catch (ToolRuleException $e) {
            return [$this->store->fail($invocation, $e->rule === 'read_only' ? ToolInvocationFailureKind::Internal : ToolInvocationFailureKind::ToolError, self::elapsed($startedAt)), null];
        } catch (Throwable) {
            return [$this->store->fail($invocation, ToolInvocationFailureKind::ToolError, self::elapsed($startedAt)), null];
        }

        $elapsed = self::elapsed($startedAt);

        // The declared OUTPUT contract is closed too: an undeclared or
        // out-of-bound result field is refused and the result is discarded —
        // it never reaches the model and it is never stored.
        try {
            $output = $definition->output->validate(is_array($raw) ? $raw : []);
        } catch (ToolRuleException) {
            return [$this->store->fail($invocation, ToolInvocationFailureKind::InvalidOutput, $elapsed), null];
        }

        // The timeout is a property of this immutable tool version; nothing is
        // duplicated onto the row, only what actually happened.
        if ($elapsed > $definition->timeoutMs) {
            return [$this->store->timeOut($invocation, $elapsed), null];
        }

        return [$this->store->succeed($invocation, $output, $elapsed), $output];
    }

    /**
     * @param  array<string, mixed>|null  $transientOutput
     */
    private function settledResult(ToolClaim $claim, ToolInvocation $invocation, bool $executed, ?array $transientOutput = null): ToolInvocationResult
    {
        return ToolInvocationResult::settled($claim->outcome, $invocation, $executed, $transientOutput);
    }

    private static function elapsed(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
