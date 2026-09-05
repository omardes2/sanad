<?php

declare(strict_types=1);

namespace App\Data\Billing;

use App\Enums\UsageDimension;
use App\Enums\UsageEventOutcome;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Everything UsageRecorder needs to write one ledger row: WHO consumed, WHAT
 * was consumed (units), WHERE (provider/model/channel) and WHICH invocation it
 * is (correlation + idempotency). Costs and the subscription/plan snapshot are
 * resolved by the recorder at write time, not by the caller.
 *
 * `outcome` is deliberately separate from "was it recorded": a provider that
 * consumed billable units is recorded even when a later stage failed.
 *
 * @param  array<string, mixed>  $metadata
 */
final readonly class UsageRecord
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        /** Null ONLY for system-attributed usage (Phase C3 health probes): company cost, no subscriber, no quota. */
        public ?User $subscriber,
        public UsageDimension $dimension,
        public string $idempotencyKey,
        public ?string $correlationId = null,
        public ?string $operation = null,
        public string $provider = 'internal',
        public ?string $model = null,
        public ?string $channel = null,
        public int $inputUnits = 0,
        public int $outputUnits = 0,
        public int $cachedUnits = 0,
        public int $quantity = 1,
        public ?int $durationMs = null,
        public UsageEventOutcome $outcome = UsageEventOutcome::Succeeded,
        public ?CarbonImmutable $occurredAt = null,
        public array $metadata = [],
        public ?string $jobRef = null,
        public ?string $jobStepRef = null,
        public ?string $toolInvocationRef = null,
        /**
         * The model id the router REQUESTED, when it differs from the id the
         * provider reported in `model` (last resort of alias resolution).
         */
        public ?string $routedModel = null,
    ) {}
}
