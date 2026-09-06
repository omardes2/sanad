<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingPeriod;
use App\Enums\PlanPriceVersionSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One version of a plan's financial terms over [effective_from, effective_until)
 * (Phase E0). Written only by PlanPriceBook; the only later write is closing
 * the period once. Never rewritten or back-dated.
 */
class PlanPriceVersion extends Model
{
    /** Storage/comparison format for the period boundaries (microsecond precision). */
    public const PERIOD_FORMAT = 'Y-m-d H:i:s.u';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'plan_id',
        'price',
        'currency',
        'billing_period',
        'effective_from',
        'effective_until',
        'source',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'billing_period' => BillingPeriod::class,
            'source' => PlanPriceVersionSource::class,
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
        ];
    }

    /**
     * Dates are written with microseconds on both engines (PostgreSQL
     * timestamp(6); SQLite text that sorts and compares lexicographically).
     */
    public function getDateFormat(): string
    {
        return self::PERIOD_FORMAT;
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function isOpen(): bool
    {
        return $this->effective_until === null;
    }

    /**
     * @return array{price: string, currency: string, billing_period: string}
     */
    public function terms(): array
    {
        return [
            'price' => (string) $this->price,
            'currency' => (string) $this->currency,
            'billing_period' => $this->billing_period->value,
        ];
    }
}
