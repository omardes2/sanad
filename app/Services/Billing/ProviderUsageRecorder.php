<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Data\Ai\AiResponse;
use App\Data\Billing\UsageRecord;
use App\Enums\UsageDimension;
use App\Enums\UsageEventOutcome;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Support\Ai\ProviderAttempt;
use App\Support\Billing\UsageKeys;

/**
 * Records what the PROVIDER actually charged us for: one ledger row per
 * PHYSICAL provider request.
 *
 * It is deliberately separate from quota. A subscriber's allowance is consumed
 * once per user-facing reply (`MeteredAgentOrchestrator`), because that is what
 * they asked for. The provider, by contrast, charges for every request it
 * served — so when the queue retries a message after a mid-turn failure and
 * call 1 is sent again, that second request is real money and gets its own row.
 * Keying provider cost by the LOGICAL position alone would silently collapse
 * the two and under-report what we paid.
 *
 * It is called the moment a response comes back, not at the end of the turn, so
 * a request that was served and billed is recorded even if the turn later
 * throws and the queue retries.
 *
 * WHAT IS NEVER INVENTED: a transport failure that produced no response and no
 * measurable usage records nothing at all. The ledger's existing distinction
 * between "unpriced / not reported" and "confirmed zero" is preserved by
 * passing through exactly what the provider reported and letting
 * CostCalculator price it — this class never guesses a token count or a cost.
 */
final class ProviderUsageRecorder
{
    public function __construct(
        private readonly UsageRecorder $recorder,
        private readonly ProviderAttempt $attempt,
    ) {}

    /**
     * @param  int  $call  the logical position of this call in the turn (1-based)
     */
    public function record(
        User $subscriber,
        Conversation $conversation,
        Message $message,
        int $call,
        AiResponse $response,
        string $providerName,
        ?string $model,
        ?string $routedModel,
        string $operation,
        UsageEventOutcome $outcome = UsageEventOutcome::Succeeded,
    ): void {
        $correlationId = UsageKeys::correlationForMessage($message);
        $attempt = $this->attempt->current();

        $this->recorder->record(new UsageRecord(
            subscriber: $subscriber,
            dimension: UsageDimension::AiReply,
            // Physical identity: this position, on this attempt at the message.
            idempotencyKey: UsageKeys::providerAttempt(UsageDimension::AiReply, $correlationId, $call, $attempt),
            correlationId: $correlationId,
            operation: $operation,
            provider: $providerName,
            model: $model,
            channel: $conversation->channelAccount?->channel?->value,
            inputUnits: $response->promptTokens ?? 0,
            outputUnits: $response->completionTokens ?? 0,
            cachedUnits: $response->cachedTokens ?? 0,
            durationMs: $response->durationMs,
            outcome: $outcome,
            metadata: array_filter([
                'message_id' => $message->id,
                'conversation_id' => $conversation->id,
                // Diagnostics only, never an identity: the logical position and
                // the provider's own reference when it gave one.
                'provider_call' => $call,
                'provider_attempt' => $attempt,
                'provider_request_id' => $response->requestId,
            ], static fn ($v): bool => $v !== null),
            routedModel: $routedModel,
        ));
    }
}
