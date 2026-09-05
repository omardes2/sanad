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

/**
 * Wraps the real AgentOrchestrator with subscription/usage enforcement, so the
 * AI orchestrator itself stays free of billing and WhatsApp concerns.
 *
 * Flow (a metered AI reply = 1 charge, weightable later):
 *  1. check() BEFORE calling AI — if disabled/over-limit, return the friendly
 *     Arabic limit message and never call the provider;
 *  2. call the inner orchestrator;
 *  3. if the AI itself failed (fallback), do NOT charge;
 *  4. charge() AFTER a real success — atomic + idempotent (key = inbound message
 *     id), so duplicates/retries never double-charge. If the atomic charge is
 *     denied at the boundary (a concurrent message took the last slot), return
 *     the limit message instead of the AI reply.
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

        // The AI failed and produced a fallback — do not consume the allowance.
        if (($reply->metadata['ai']['failed'] ?? false) === true) {
            return $reply;
        }

        $ai = $reply->metadata['ai'] ?? [];

        $charge = $this->usage->charge(
            subscriber: $user,
            dimension: self::DIMENSION,
            idempotencyKey: 'ai_reply:'.$message->id,
            meta: [
                'provider' => $ai['provider'] ?? 'internal',
                'model' => $ai['model'] ?? null,
            ],
            inputTokens: (int) ($ai['prompt_tokens'] ?? 0),
            outputTokens: (int) ($ai['completion_tokens'] ?? 0),
        );

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
