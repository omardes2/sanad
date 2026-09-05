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
    /** Grouping key for active subscriptions that have no plan. */
    public const PLAN_KEY_NONE = 'none';

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
