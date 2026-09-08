<?php

declare(strict_types=1);

namespace App\Agents;

use App\Contracts\AgentOrchestrator;
use App\Data\AgentResponseData;
use App\Data\Billing\UsageDecision;
use App\Enums\MessageType;
use App\Enums\UsageDimension;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Billing\UsageEngine;
use App\Services\Billing\UsageLimitResponder;
use App\Support\Billing\UsageKeys;

/**
 * Wraps the real AgentOrchestrator with metering, so the AI orchestrator itself
 * stays free of billing and channel concerns. This class owns exactly ONE of
 * the two accounting questions:
 *
 *  QUOTA (here, only when billing.enforce) — UsageEngine::check() BEFORE
 *      calling AI (never call the provider when already over the limit), and
 *      UsageEngine::charge() AFTER, consuming quota atomically. The key is
 *      scoped to the MESSAGE, so however many provider round-trips or queue
 *      retries one answer took, the subscriber's allowance is consumed once.
 *  PROVIDER COST (not here) — recorded per PHYSICAL provider request by
 *      ProviderUsageRecorder, the moment each response comes back inside the
 *      turn. A queue retry that re-sends a call is real money and gets its own
 *      ledger row.
 *
 * The two are deliberately independent and separately idempotent: losing the
 * quota boundary race returns the limit message, but the cost we already
 * incurred stays recorded, because the ledger never depends on quota
 * accounting.
 *
 * A failed AI call (fallback reply) consumed nothing billable → nothing is
 * charged. Keys: one correlation_id per inbound message (see UsageKeys).
 */
class MeteredAgentOrchestrator implements AgentOrchestrator
{
    private const DIMENSION = UsageDimension::AiReply;

    public function __construct(
        private readonly AgentOrchestrator $inner,
        private readonly UsageEngine $usage,
        private readonly UsageLimitResponder $responder,
    ) {}

    public function handle(User $user, Conversation $conversation, Message $message): AgentResponseData
    {
        $precheck = $this->usage->check($user, self::DIMENSION);

        if (! $precheck->allowed()) {
            return $this->deniedResponse($precheck);
        }

        $reply = $this->inner->handle($user, $conversation, $message);

        // The AI failed and produced a fallback — nothing billable was consumed.
        if (($reply->metadata['ai']['failed'] ?? false) === true) {
            return $reply;
        }

        // The quota key is scoped to the MESSAGE, not to a physical attempt: an
        // infrastructure retry must never consume the subscriber's allowance a
        // second time for the same reply.
        $idempotencyKey = UsageKeys::invocation(self::DIMENSION, UsageKeys::correlationForMessage($message));

        // 1) Provider cost is NOT recorded here. Every physical provider request
        //    is metered by ProviderUsageRecorder the moment it returns, inside
        //    the turn — so a request that was served and billed is recorded even
        //    when the turn later fails and the queue retries, and the retry's own
        //    re-send gets its own row instead of being deduplicated into it.
        // 2) Quota — ONE charge per user-facing reply, whatever it took to
        //    produce it, and whatever the queue had to retry. The dimension is
        //    `ai_reply`: a subscriber's allowance counts answers, not the
        //    provider round-trips behind one, and an infrastructure retry must
        //    never consume it twice — which the message-scoped key guarantees.
        $charge = $this->usage->charge($user, self::DIMENSION, $idempotencyKey);

        // Lost the boundary race: the allowance was exhausted concurrently.
        if ($charge->limitReached()) {
            return $this->deniedResponse($charge);
        }

        return $reply;
    }

    private function deniedResponse(UsageDecision $decision): AgentResponseData
    {
        return new AgentResponseData(
            text: $this->responder->message($decision),
            type: MessageType::Text,
            metadata: ['usage' => ['denied' => $decision->outcome->value, 'dimension' => self::DIMENSION->value]],
        );
    }
}
