<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UsageEventOutcome;
use Database\Factories\UsageEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The usage/cost LEDGER — one row per billable invocation, written only by
 * UsageRecorder (always on, independent of enforcement).
 *
 * Historical by design: subscriber_id / subscription_id / plan_id / plan_slug
 * are snapshots taken at record time (no foreign keys). user_id is the live FK
 * (nulled if the user is hard-deleted); subscriber_id keeps the pseudonymous
 * owner forever, so a cost never loses who caused it. `outcome` is explicit
 * for recorded rows and NULL (unknown) for rows that pre-date the ledger. `type` is the usage
 * dimension; `cost` mirrors `total_cost` for backward compatibility.
 */
class UsageEvent extends Model
{
    /** @use HasFactory<UsageEventFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'subscriber_id',
        'subscription_id',
        'plan_id',
        'plan_slug',
        'type',
        'operation',
        'channel',
        'outcome',
        'idempotency_key',
        'correlation_id',
        'provider',
        'model',
        'input_units',
        'output_units',
        'cached_units',
        'quantity',
        'duration_ms',
        'cost',
        'provider_cost',
        'communication_cost',
        'external_cost',
        'total_cost',
        'currency',
        'metadata',
        'occurred_at',
        'job_ref',
        'job_step_ref',
        'tool_invocation_ref',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'outcome' => UsageEventOutcome::class,
            'input_units' => 'integer',
            'output_units' => 'integer',
            'cached_units' => 'integer',
            'quantity' => 'integer',
            'duration_ms' => 'integer',
            'cost' => 'decimal:6',
            'provider_cost' => 'decimal:6',
            'communication_cost' => 'decimal:6',
            'external_cost' => 'decimal:6',
            'total_cost' => 'decimal:6',
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Snapshot reference (no FK): may point at a since-deleted subscription. */
    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** Snapshot reference (no FK): the plan in force when the event occurred. */
    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
