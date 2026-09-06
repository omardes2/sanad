<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Payments\ImmutableFinancialRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One immutable (partial or full) refund against a succeeded payment (Phase E1). */
class CustomerRefund extends Model
{
    use ImmutableFinancialRecord;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = ['customer_payment_id', 'gateway', 'gateway_refund_ref', 'idempotency_key', 'amount', 'currency', 'refunded_at', 'reason_code', 'evidence_ref', 'recorded_by_ref', 'created_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'refunded_at' => 'datetime'];
    }

    public function getDateFormat(): string
    {
        return CustomerPayment::TIMESTAMP_FORMAT;
    }

    /** @return BelongsTo<CustomerPayment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(CustomerPayment::class, 'customer_payment_id');
    }

    /** @return HasMany<RefundAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(RefundAllocation::class);
    }
}
