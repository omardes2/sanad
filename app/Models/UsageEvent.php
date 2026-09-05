<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CostSource;
use App\Enums\UsageEventOutcome;
use Database\Factories\UsageEventFactory;
use Illuminate\Database\Eloquent\Builder;
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
 *
 * Pricing (Phase B2): ai_model_id / model_price_id are snapshot ids (no FK) of
 * the model and the historical price the row was costed with, and
 * pricing_snapshot holds the exact rates. `cost_source` says how the provider
 * cost was obtained; when it is NULL (pre-B2 rows) or an unknown-cost marker
 * (`none`, `currency_mismatch`) the zero in the cost columns is NOT a free
 * operation — the row is UNPRICED and reports must count it as such.
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
        'ai_model_id',
        'model_price_id',
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
        'pricing_snapshot',
        'cost_source',
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
            'pricing_snapshot' => 'array',
            'cost_source' => CostSource::class,
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * Rows whose cost is KNOWN (costed with a model price or a config rate).
     *
     * @param  Builder<UsageEvent>  $query
     * @return Builder<UsageEvent>
     */
    public function scopePriced(Builder $query): Builder
    {
        return $query->whereIn('cost_source', [CostSource::ModelPrice->value, CostSource::ConfigRate->value]);
    }

    /**
     * Rows whose cost is UNKNOWN: unpriced B2 rows and every pre-B2 row
     * (cost_source NULL). Never sum their cost columns as real cost.
     *
     * @param  Builder<UsageEvent>  $query
     * @return Builder<UsageEvent>
     */
    public function scopeUnpriced(Builder $query): Builder
    {
        return $query->where(static function (Builder $q): void {
            $q->whereNull('cost_source')->orWhereIn('cost_source', CostSource::unknownValues());
        });
    }

    public function hasKnownCost(): bool
    {
        return $this->cost_source instanceof CostSource && $this->cost_source->isKnown();
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
