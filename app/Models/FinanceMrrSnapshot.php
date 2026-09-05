<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One (UTC day, currency, plan) row of the Calculated MRR history, written
 * only by `sanad:finance:snapshot` for the current day and never rewritten.
 * plan_id is a historical reference without a foreign key.
 */
class FinanceMrrSnapshot extends Model
{
    /** Grouping key for subscriptions that have no plan (also the empty-day marker row). */
    public const PLAN_KEY_NONE = 'none';

    /**
     * Stable identity of a plan inside a snapshot: "plan:<id>". Never derived
     * from the slug, price, period or any other mutable attribute — those are
     * descriptive history on the row, not identity — so renaming a plan later
     * cannot make it look like a new plan in the MRR history.
     */
    public static function planKeyFor(?int $planId): string
    {
        return $planId === null ? self::PLAN_KEY_NONE : 'plan:'.$planId;
    }

    /** The marker row of a day that had nothing to snapshot (or the no-plan group): never revenue. */
    public function isMarker(): bool
    {
        return $this->plan_key === self::PLAN_KEY_NONE;
    }

    /** ISO 4217 "no currency": rows for subscriptions without a plan. */
    public const NO_CURRENCY = 'XXX';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'snapshot_date',
        'captured_at',
        'currency',
        'plan_id',
        'plan_key',
        'plan_slug',
        'plan_price',
        'billing_period',
        'active_count',
        'trialing_count',
        'past_due_count',
        'mrr_normalized',
        'calculation_version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Kept as the plain "Y-m-d" string it is written with, so equality on
            // the unique key behaves identically on PostgreSQL (date) and SQLite (text).
            'captured_at' => 'datetime',
            'plan_price' => 'decimal:2',
            'active_count' => 'integer',
            'trialing_count' => 'integer',
            'past_due_count' => 'integer',
            'mrr_normalized' => 'decimal:6',
            'calculation_version' => 'integer',
        ];
    }
}
