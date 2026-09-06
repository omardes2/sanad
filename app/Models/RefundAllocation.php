<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Payments\ImmutableFinancialRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Attribution of a refund to the payment allocation it reverses, append-only (Phase E1). */
class RefundAllocation extends Model
{
    use ImmutableFinancialRecord;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = ['customer_refund_id', 'payment_allocation_id', 'amount', 'currency', 'allocated_at', 'actor_ref', 'reason_code', 'created_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'allocated_at' => 'datetime'];
    }

    /** @return BelongsTo<CustomerRefund, $this> */
    public function refund(): BelongsTo
    {
        return $this->belongsTo(CustomerRefund::class, 'customer_refund_id');
    }

    /** @return BelongsTo<PaymentAllocation, $this> */
    public function allocation(): BelongsTo
    {
        return $this->belongsTo(PaymentAllocation::class, 'payment_allocation_id');
    }
}
