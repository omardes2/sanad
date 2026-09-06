<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CostComponent;
use App\Enums\CostInvoiceEventType;
use App\Exceptions\Payments\ImmutableFinancialRecordException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Supplier invoice EVIDENCE (Phase E2): identity + immutable facts; the
 * lifecycle is cost_invoice_events and current_status / latest_event_id /
 * superseded_by_id are a projection the service moves under a row lock.
 */
class CostInvoice extends Model
{
    public const TIMESTAMP_FORMAT = 'Y-m-d H:i:s.u';

    /** Facts frozen at creation. */
    public const IMMUTABLE = ['component', 'counterparty_key', 'invoice_ref', 'idempotency_key', 'issued_at', 'period_start', 'period_end', 'currency', 'total_amount', 'evidence_ref', 'recorded_by_ref'];

    /**
     * @var list<string>
     */
    protected $fillable = ['component', 'counterparty_key', 'invoice_ref', 'idempotency_key', 'issued_at', 'period_start', 'period_end', 'currency', 'total_amount', 'evidence_ref', 'current_status', 'latest_event_id', 'superseded_by_id', 'recorded_by_ref'];

    protected static function booted(): void
    {
        static::updating(static function (self $invoice): void {
            foreach (self::IMMUTABLE as $attribute) {
                if ($invoice->isDirty($attribute)) {
                    throw ImmutableFinancialRecordException::for($invoice, "update of immutable fact [{$attribute}]");
                }
            }
        });

        static::deleting(static function (self $invoice): void {
            throw ImmutableFinancialRecordException::for($invoice, 'delete');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'component' => CostComponent::class,
            'current_status' => CostInvoiceEventType::class,
            'total_amount' => 'decimal:6',
            'issued_at' => 'datetime',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
        ];
    }

    /** @return HasMany<CostInvoiceEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(CostInvoiceEvent::class);
    }

    /** @return HasMany<CostInvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(CostInvoiceLine::class);
    }

    /** @return HasMany<CostInvoiceAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(CostInvoiceAllocation::class);
    }

    public function isConfirmed(): bool
    {
        return $this->current_status === CostInvoiceEventType::Confirmed;
    }

    /** Opaque state token for stale protection: the latest lifecycle event. */
    public function stateToken(): string
    {
        return 'i:'.($this->latest_event_id ?? 0);
    }
}
