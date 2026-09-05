<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'subscriber_id',
        'plan_id',
        'status',
        'started_at',
        'trial_ends_at',
        'current_period_start',
        'current_period_end',
        'renews_at',
        'cancelled_at',
        'provider',
        'provider_subscription_id',
        'meta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'started_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'renews_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subscriber_id');
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Whether this subscription currently grants access to metered capabilities:
     * an entitled status that has not lapsed past its period/trial end.
     */
    public function isEntitled(): bool
    {
        if (! $this->status->isEntitled()) {
            return false;
        }

        if ($this->status === SubscriptionStatus::Trialing && $this->trial_ends_at !== null) {
            return $this->trial_ends_at->isFuture();
        }

        if ($this->current_period_end !== null) {
            return $this->current_period_end->isFuture();
        }

        return true;
    }
}
