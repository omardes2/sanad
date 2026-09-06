<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SubscriptionEventSource;
use App\Enums\SubscriptionEventType;
use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * One APPEND-ONLY subscription transition (Phase E0). Written only by
 * SubscriptionHistory inside the mutating transaction; never updated.
 */
class SubscriptionEvent extends Model
{
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'subscription_id',
        'subscriber_id',
        'event_type',
        'from_status',
        'to_status',
        'from_plan_id',
        'to_plan_id',
        'from_period_start',
        'from_period_end',
        'to_period_start',
        'to_period_end',
        'effective_at',
        'source',
        'actor_ref',
        'reason',
        'correlation_id',
        'metadata',
        'baseline_key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_type' => SubscriptionEventType::class,
            'from_status' => SubscriptionStatus::class,
            'to_status' => SubscriptionStatus::class,
            'source' => SubscriptionEventSource::class,
            'from_period_start' => 'datetime',
            'from_period_end' => 'datetime',
            'to_period_start' => 'datetime',
            'to_period_end' => 'datetime',
            'effective_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public static function baselineKeyFor(int $subscriptionId): string
    {
        return 'sub:'.$subscriptionId;
    }
}
