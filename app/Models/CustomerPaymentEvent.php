<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CustomerPaymentEventType;
use App\Enums\PaymentSource;
use App\Support\Payments\ImmutableFinancialRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One append-only payment lifecycle event (Phase E1). Never updated or deleted. */
class CustomerPaymentEvent extends Model
{
    use ImmutableFinancialRecord;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = ['customer_payment_id', 'event_type', 'occurred_at', 'source', 'actor_ref', 'reason_code', 'evidence_ref', 'metadata', 'created_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_type' => CustomerPaymentEventType::class,
            'source' => PaymentSource::class,
            'occurred_at' => 'datetime',
            'metadata' => 'array',
        ];
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
}
