<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Data\Ai\AiToolCall;
use App\Data\Ai\ToolResult;
use App\Enums\ToolClaimOutcome;
use App\Enums\ToolInvocationStatus;
use App\Exceptions\Tools\ToolDefinitionException;
use App\Exceptions\Tools\ToolRuleException;
use App\Models\Message;
use App\Services\Tools\ToolExecutor;
use App\Support\Tools\ToolCallPlan;
use App\Support\Tools\ToolCatalog;
use App\Support\Tools\ToolTurnBudget;

/**
 * Turns ONE round of provider-proposed tool calls into executed invocations and
 * bounded results the model may see (Phase F4).
 *
 * WHAT THE PROVIDER CONTROLS: the wire name of a tool it was offered, and the
 * arguments. Nothing else. The subscriber, the conversation, consent, the
 * capability, the RBAC permission, approval, the call index, the idempotency
 * key, the invocation status, whether execution happens at all, retries and
 * rate policy are all decided by the server here and downstream — exactly as
 * F1–F3 already enforce.
 *
 * THE SLOT IS THE SERVER'S. `call_index` is the position of the call in this
 * turn's ordered plan, offset by the rounds already executed, so round two
 * continues round one's numbering and the two never collide. A provider's own
 * call id is diagnostic only: it is echoed back so the model can match a result
 * to its request, and it never becomes an identity.
 *
 * WHAT GOES BACK TO THE MODEL: the tool's schema-validated output, or a bounded
 * machine-readable reason. Never an invocation id, an idempotency key, a
 * subscriber id, a capability, an audit record or an exception message.
 */
final class ToolTurnRunner
{
    public function __construct(
        private readonly ToolCatalog $catalog,
        private readonly ToolCallPlan $plan,
        private readonly ToolExecutor $executor,
    ) {}

    /**
     * Execute one round. The caller has already proven the WHOLE batch fits the
     * remaining budget — a batch is all-or-nothing and is never partially run.
     *
     * @param  list<AiToolCall>  $calls
     * @return list<ToolResult>
     */
    public function run(Message $message, array $calls, ToolTurnBudget $budget): array
    {
        $startIndex = $budget->toolInvocations() + 1;
        $results = [];

        foreach (array_values($calls) as $position => $call) {
            $results[] = $this->one($message, $call, $startIndex + $position);
        }

        return $results;
    }

    // ------------------------------------------------------------------

    private function one(Message $message, AiToolCall $call, int $slot): ToolResult
    {
        // A name the catalog does not offer this turn is refused here, and would
        // be refused again by the executor: hiding is never the only control.
        $definition = $this->catalog->resolve($call->name);

        if ($definition === null) {
            return ToolResult::failed($call, 'unknown_tool');
        }

        try {
            $request = $this->plan->one($message, $definition->key->value(), $call->arguments, $slot);
        } catch (ToolDefinitionException) {
            return ToolResult::failed($call, 'unknown_tool');
        } catch (ToolRuleException $e) {
            return ToolResult::failed($call, $e->rule === 'subscriber' ? 'subscriber_missing' : 'invalid_input');
        }

        $result = $this->executor->execute($request);

        // Refused before a claim: there is no invocation, only a reason.
        if ($result->invocation === null) {
            return ToolResult::failed($call, (string) $result->refusal?->value);
        }

        return match (true) {
            $result->claim === ToolClaimOutcome::Conflict => ToolResult::failed($call, 'conflict'),
            $result->claim === ToolClaimOutcome::InFlight => ToolResult::failed($call, 'in_flight'),
            // The result's own accessor, not the row: a tool may be allowed to
            // tell the model more than it is allowed to store (Phase G).
            $result->invocation->status === ToolInvocationStatus::Succeeded => ToolResult::ok($call, $result->output() ?? []),
            $result->invocation->status === ToolInvocationStatus::Refused => ToolResult::failed($call, (string) $result->invocation->refusal_reason?->value),
            default => ToolResult::failed($call, (string) $result->invocation->failure_kind?->value),
        };
    }
}
