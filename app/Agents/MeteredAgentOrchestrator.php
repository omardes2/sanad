<?php

declare(strict_types=1);

namespace App\Agents;

use App\Contracts\AgentOrchestrator;
use App\Data\AgentResponseData;
use App\Data\Billing\UsageDecision;
use App\Data\Billing\UsageRecord;
use App\Enums\MessageType;
use App\Enums\UsageDimension;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Billing\UsageEngine;
use App\Services\Billing\UsageLimitResponder;
use App\Services\Billing\UsageRecorder;
use App\Support\Billing\UsageKeys;

/**
 * Wraps the real AgentOrchestrator with metering, so the AI orchestrator itself
 * stays free of billing and channel concerns. Two independent, idempotent
 * steps run after a real AI response:
 *
 *  RECORD (always) — UsageRecorder writes the cost/usage ledger row for what the
 *      provider consumed. Runs whether billing.enforce is on or off.
 *  ENFORCE (only when billing.enforce) — UsageEngine::check() BEFORE calling AI
 *      (never call the provider when already over the limit), and
 *      UsageEngine::charge() AFTER, consuming quota atomically. Losing the
 *      boundary race returns the limit message — but the cost we incurred stays
 *      recorded, because the ledger never depends on quota accounting.
 *
 * A failed AI call (fallback reply) consumed nothing billable → nothing is
 * recorded and nothing is charged. Keys: one correlation_id per inbound
 * message, one idempotency_key per billable invocation (see UsageKeys).
 */
class MeteredAgentOrchestrator implements AgentOrchestrator
{
    private const DIMENSION = UsageDimension::AiReply;

    public function __construct(
        private readonly AgentOrchestrator $inner,
        private readonly UsageEngine $usage,
        private readonly UsageLimitResponder $responder,
        private readonly UsageRecorder $recorder,
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

        $ai = $reply->metadata['ai'] ?? [];
        $correlationId = UsageKeys::correlationForMessage($message);
        $idempotencyKey = UsageKeys::invocation(self::DIMENSION, $correlationId);

        // 1) Ledger — always.
        $this->recorder->record(new UsageRecord(
            subscriber: $user,
            dimension: self::DIMENSION,
            idempotencyKey: $idempotencyKey,
            correlationId: $correlationId,
            operation: $ai['operation'] ?? null,
            provider: (string) ($ai['provider'] ?? 'internal'),
            model: $ai['model'] ?? null,
            routedModel: $ai['routed_model'] ?? null,
            channel: $conversation->channelAccount?->channel?->value,
            inputUnits: (int) ($ai['prompt_tokens'] ?? 0),
            outputUnits: (int) ($ai['completion_tokens'] ?? 0),
            cachedUnits: (int) ($ai['cached_tokens'] ?? 0),
            durationMs: isset($ai['duration_ms']) ? (int) $ai['duration_ms'] : null,
            metadata: ['message_id' => $message->id, 'conversation_id' => $conversation->id],
        ));

        // 2) Quota — only when enforcement is on (charge() itself is gated).
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
