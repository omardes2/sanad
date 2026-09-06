<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CustomerPaymentEventType;
use App\Exceptions\Payments\ImmutableFinancialRecordException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Payment identity + immutable facts (Phase E1). The lifecycle is the
 * append-only customer_payment_events table; current_status / latest_event_id
 * are a projection the service maintains under a row lock. Immutable facts
 * cannot be changed after creation (the model refuses) and the row is never
 * deleted.
 */
class CustomerPayment extends Model
{
    public const GATEWAY_MANUAL = 'manual';

    public const AMOUNT_SCALE = 2;

    public const TIMESTAMP_FORMAT = 'Y-m-d H:i:s.u';

    /** Facts frozen at creation. */
    public const IMMUTABLE = ['subscriber_id', 'gateway', 'gateway_payment_ref', 'idempotency_key', 'amount', 'currency', 'gateway_fee_amount', 'fee_currency', 'received_at', 'reference', 'reason_code', 'evidence_ref', 'recorded_by_ref'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'subscriber_id', 'user_id', 'gateway', 'gateway_payment_ref', 'idempotency_key', 'amount', 'currency',
        'gateway_fee_amount', 'fee_currency', 'received_at', 'reference', 'reason_code', 'evidence_ref',
        'current_status', 'latest_event_id', 'recorded_by_ref',
    ];

    protected static function booted(): void
    {
        static::updating(static function (self $payment): void {
            foreach (self::IMMUTABLE as $attribute) {
                if ($payment->isDirty($attribute)) {
                    throw ImmutableFinancialRecordException::for($payment, "update of immutable fact [{$attribute}]");
                }
            }
        });

        static::deleting(static function (self $payment): void {
            throw ImmutableFinancialRecordException::for($payment, 'delete');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'gateway_fee_amount' => 'decimal:2',
            'received_at' => 'datetime',
            'current_status' => CustomerPaymentEventType::class,
        ];
    }

    public function getDateFormat(): string
    {
        return self::TIMESTAMP_FORMAT;
    }

    /** @return HasMany<CustomerPaymentEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(CustomerPaymentEvent::class);
    }

    /** @return HasMany<CustomerRefund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(CustomerRefund::class);
    }

    /** @return HasMany<PaymentAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Event-based truth: cash was collected iff a `succeeded` event exists. */
    public function hasSucceeded(): bool
    {
        return $this->events()->where('event_type', CustomerPaymentEventType::Succeeded->value)->exists();
    }

    /** Gateway fee is UNKNOWN (never zero) when NULL. */
    public function feeIsKnown(): bool
    {
        return $this->gateway_fee_amount !== null;
    }

    /** Opaque state token for stale protection: the latest lifecycle event. */
    public function stateToken(): string
    {
        return 'e:'.($this->latest_event_id ?? 0);
    }
}
