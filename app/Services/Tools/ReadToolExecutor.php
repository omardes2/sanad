<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Data\Tools\ToolCallRequest;
use App\Data\Tools\ToolClaim;
use App\Data\Tools\ToolInvocationResult;
use App\Enums\ToolInvocationFailureKind;
use App\Enums\ToolInvocationRefusalReason;
use App\Enums\ToolInvocationStatus;
use App\Enums\ToolSideEffect;
use App\Exceptions\Tools\ToolDefinitionException;
use App\Exceptions\Tools\ToolRuleException;
use App\Models\Message;
use App\Models\ToolInvocation;
use App\Models\User;
use App\Services\Tools\Readers\MemoryReader;
use App\Support\Tools\ReadOnlyQueryGuard;
use App\Support\Tools\ToolCallPlan;
use Throwable;

/**
 * Executes SYNCHRONOUS READ tools, and nothing else (Phase F2).
 *
 * The handler of a tool is resolved from the CODE ALLOWLIST below, keyed by
 * `name@version`. Nothing is ever resolved from registry metadata, from the
 * database or from a payload: a definition still cannot name a class, and this
 * map is the only place that says what actually runs. A definition whose
 * side-effect class is not `read` is not executable in this phase and is
 * refused before an invocation is even claimed.
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
        'memory.read@1' => [MemoryReader::class, 'read'],
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
        if ($request->definition->sideEffect !== ToolSideEffect::Read || ! array_key_exists($request->toolKey(), self::HANDLERS)) {
            return ToolInvocationResult::refusedBeforeClaim(ToolInvocationRefusalReason::SideEffectNotExecutable);
        }

        if (! User::query()->whereKey($request->subscriber->getKey())->exists()) {
            return ToolInvocationResult::refusedBeforeClaim(ToolInvocationRefusalReason::SubscriberMissing);
        }

        $claim = $this->store->claim($request);

        // Replay, in flight and conflict all changed nothing and execute nothing.
        if (! $claim->isClaimed()) {
            return ToolInvocationResult::settled($claim->outcome, $claim->invocation, executed: false);
        }

        $invocation = $claim->invocation;
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

        return $this->settledResult($claim, $this->run($request, $invocation), executed: true);
    }

    // ------------------------------------------------------------------

    private function run(ToolCallRequest $request, ToolInvocation $invocation): ToolInvocation
    {
        $definition = $request->definition;
        [$class, $method] = self::HANDLERS[$request->toolKey()];
        $startedAt = hrtime(true);

        try {
            $raw = $this->guard->run(fn (): mixed => app($class)->{$method}($request->subscriber, $request->input->values));
        } catch (ToolRuleException $e) {
            return $this->store->fail($invocation, $e->rule === 'read_only' ? ToolInvocationFailureKind::Internal : ToolInvocationFailureKind::ToolError, self::elapsed($startedAt));
        } catch (Throwable) {
            return $this->store->fail($invocation, ToolInvocationFailureKind::ToolError, self::elapsed($startedAt));
        }

        $elapsed = self::elapsed($startedAt);

        // The declared OUTPUT contract is closed too: an undeclared or
        // out-of-bound result field is refused and the result is discarded —
        // it never reaches the model and it is never stored.
        try {
            $output = $definition->output->validate(is_array($raw) ? $raw : []);
        } catch (ToolRuleException) {
            return $this->store->fail($invocation, ToolInvocationFailureKind::InvalidOutput, $elapsed);
        }

        // The timeout is a property of this immutable tool version; nothing is
        // duplicated onto the row, only what actually happened.
        if ($elapsed > $definition->timeoutMs) {
            return $this->store->timeOut($invocation, $elapsed);
        }

        return $this->store->succeed($invocation, $output, $elapsed);
    }

    private function settledResult(ToolClaim $claim, ToolInvocation $invocation, bool $executed): ToolInvocationResult
    {
        return ToolInvocationResult::settled($claim->outcome, $invocation, $executed);
    }

    private static function elapsed(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
