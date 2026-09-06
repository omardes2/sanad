<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Payments\ImmutableFinancialRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Attribution of collected cash to one subscription service period (the
 * period of one subscription_events row), append-only (Phase E1). A refund
 * never modifies it — see RefundAllocation.
 */
class PaymentAllocation extends Model
{
    use ImmutableFinancialRecord;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = ['customer_payment_id', 'subscription_event_id', 'subscription_id', 'subscriber_id', 'period_start', 'period_end', 'amount', 'currency', 'allocated_at', 'actor_ref', 'reason_code', 'created_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'period_start' => 'datetime', 'period_end' => 'datetime', 'allocated_at' => 'datetime'];
    }

    /** @return BelongsTo<CustomerPayment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(CustomerPayment::class, 'customer_payment_id');
    }

    /** @return BelongsTo<SubscriptionEvent, $this> */
    public function subscriptionEvent(): BelongsTo
    {
        return $this->belongsTo(SubscriptionEvent::class);
    }

    /** @return HasMany<RefundAllocation, $this> */
    public function refundAllocations(): HasMany
    {
        return $this->hasMany(RefundAllocation::class);
    }
}
