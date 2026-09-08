<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Data\Tools\ToolCallRequest;
use App\Data\Tools\ToolInvocationResult;
use App\Enums\ToolInvocationRefusalReason;
use App\Enums\ToolSideEffect;
use App\Exceptions\Tools\ToolDefinitionException;
use App\Exceptions\Tools\ToolRuleException;
use App\Models\Message;
use App\Support\Tools\ToolCallPlan;

/**
 * The production entry point for one tool call, and the single place that owns
 * the REJECTION PRECEDENCE (Phase F3-V1).
 *
 * The order is fixed and no condition may mask or redefine another:
 *
 *   1. Resolve the exact registered tool version — an unknown name or version
 *      is `unknown_tool`.
 *   2. Validate the input against that version's closed schema — anything it
 *      refuses is `invalid_input`.
 *   3. Check the EXECUTABLE SIDE-EFFECT CLASS. `read` and local `write` are
 *      executable in V1; `external_write` and `irreversible` are
 *      `side_effect_not_executable` — even when they ALSO require approval.
 *      That is why `reminder.create@1` (external_write + approval) answers
 *      `side_effect_not_executable` and never `approval_required`.
 *   4. Check APPROVAL. A `write` whose definition requires approval is
 *      `approval_required`: F3-V1 builds no approval mechanism and fails
 *      closed. (F1 forbids a `read` from requiring approval at all.)
 *   5. Only then claim, authorize and execute, through the executor for that
 *      class.
 *
 * Steps 1–4 all happen BEFORE any claim, so none of them creates an invocation
 * row, and none of them reads or touches the invocation that may already own
 * the slot.
 */
final class ToolExecutor
{
    public function __construct(
        private readonly ToolCallPlan $plan,
        private readonly ReadToolExecutor $reads,
        private readonly WriteToolExecutor $writes,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function call(Message $message, string $key, array $arguments): ToolInvocationResult
    {
        try {
            // 1 and 2: the plan resolves the exact version and canonicalises the
            // input against its closed schema.
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
        $definition = $request->definition;

        // 3: the class decides first, always.
        if (! in_array($definition->sideEffect, [ToolSideEffect::Read, ToolSideEffect::Write], true)) {
            return ToolInvocationResult::refusedBeforeClaim(ToolInvocationRefusalReason::SideEffectNotExecutable);
        }

        // 4: then, and only then, approval.
        if ($definition->needsApproval()) {
            return ToolInvocationResult::refusedBeforeClaim(ToolInvocationRefusalReason::ApprovalRequired);
        }

        // 5: identity, consent, execution.
        return $definition->sideEffect === ToolSideEffect::Read
            ? $this->reads->execute($request)
            : $this->writes->execute($request);
    }
}
