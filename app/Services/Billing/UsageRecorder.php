<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Data\Billing\RecordResult;
use App\Data\Billing\UsageRecord;
use App\Models\Subscription;
use App\Models\UsageEvent;
use App\Services\Billing\Pricing\CostCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY writer of usage_events — Sanad's usage/cost ledger.
 *
 * Recording is ALWAYS ON: it does not read billing.enforce, never touches
 * usage_counters, and never decides whether an operation is allowed. It records
 * what the provider consumed and what it cost us; whether the subscriber was
 * within quota is UsageEngine's separate concern.
 *
 * Idempotent by construction: the insert is INSERT ... ON CONFLICT DO NOTHING
 * on idempotency_key, so a job retry or duplicate webhook can never produce a
 * second row for the same billable invocation — while a genuinely new
 * invocation for the same logical request (different key, same correlation_id)
 * is recorded as its own row.
 *
 * Costs are computed at write time and stored on the row (immutable history).
 * Since Phase B2 the CostCalculator prices AI usage with the historical model
 * price in force at occurred_at (model_price_id + pricing_snapshot), keeps the
 * B1 configurable rates for WhatsApp / as a legacy fallback, and marks rows it
 * cannot price as UNPRICED (cost_source none / currency_mismatch) instead of
 * pretending they were free. Nothing ever recomputes a stored cost.
 */
class UsageRecorder
{
    public function __construct(private readonly CostCalculator $costs) {}

    public function record(UsageRecord $record): RecordResult
    {
        $now = CarbonImmutable::now();
        $occurredAt = $record->occurredAt ?? $now;
        $subscription = $this->subscriptionSnapshot($record);
        $cost = $this->costs->calculate($record, $occurredAt);

        $row = [
            'user_id' => $record->subscriber->id,
            'subscriber_id' => $record->subscriber->id, // immutable attribution snapshot
            'subscription_id' => $subscription?->id,
            'plan_id' => $subscription?->plan_id,
            'plan_slug' => $subscription?->plan?->slug,
            'type' => $record->dimension->value,
            'operation' => $record->operation,
            'channel' => $record->channel,
            'outcome' => $record->outcome->value, // always explicit for recorded rows
            'idempotency_key' => $record->idempotencyKey,
            'correlation_id' => $record->correlationId,
            'provider' => $record->provider,
            'model' => $record->model,
            'ai_model_id' => $cost->aiModelId,
            'model_price_id' => $cost->modelPriceId,
            'input_units' => $record->inputUnits,
            'output_units' => $record->outputUnits,
            'cached_units' => $record->cachedUnits,
            'quantity' => $record->quantity,
            'duration_ms' => $record->durationMs,
            'cost' => $cost->totalCost, // compatibility mirror of total_cost
            'provider_cost' => $cost->providerCost,
            'communication_cost' => $cost->communicationCost,
            'external_cost' => $cost->externalCost,
            'total_cost' => $cost->totalCost,
            'pricing_snapshot' => $cost->snapshot !== null ? json_encode($cost->snapshot, JSON_UNESCAPED_UNICODE) : null,
            'cost_source' => $cost->source->value,
            'currency' => $cost->currency,
            'metadata' => $record->metadata !== [] ? json_encode($record->metadata, JSON_UNESCAPED_UNICODE) : null,
            'occurred_at' => $occurredAt->toDateTimeString(),
            'job_ref' => $record->jobRef,
            'job_step_ref' => $record->jobStepRef,
            'tool_invocation_ref' => $record->toolInvocationRef,
            'created_at' => $now->toDateTimeString(),
            'updated_at' => $now->toDateTimeString(),
        ];

        // Atomic, race-safe "record once": pgsql → ON CONFLICT DO NOTHING,
        // sqlite → INSERT OR IGNORE. 1 affected row = this call created it.
        $created = DB::table('usage_events')->insertOrIgnore($row) === 1;

        /** @var UsageEvent $event */
        $event = UsageEvent::query()->where('idempotency_key', $record->idempotencyKey)->firstOrFail();

        return new RecordResult($event, $created);
    }

    /**
     * The subscription (with plan) in force NOW, captured as a snapshot on the
     * row. Null when the subscriber has none — the cost is still recorded.
     */
    private function subscriptionSnapshot(UsageRecord $record): ?Subscription
    {
        return Subscription::query()
            ->with('plan')
            ->where('subscriber_id', $record->subscriber->id)
            ->first();
    }
}
