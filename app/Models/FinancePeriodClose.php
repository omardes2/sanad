<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PeriodCloseStatus;
use App\Support\Payments\ImmutableFinancialRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One append-only close / reopen record with its frozen figures and canonical inputs snapshot (Phase E4). */
class FinancePeriodClose extends Model
{
    use ImmutableFinancialRecord;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = ['scope_id', 'period_start', 'period_end', 'reporting_currency', 'status', 'revision', 'previous_close_id', 'reopened_close_id', 'idempotency_key', 'gross_cash_collected', 'refunds', 'net_cash', 'gateway_fees', 'net_cash_after_gateway_fees', 'reconciled_service_cost', 'reconciled_cash_contribution', 'conditions', 'inputs_snapshot', 'input_hash', 'typed_confirmation', 'reason_code', 'evidence_ref', 'closed_at', 'actor_ref', 'created_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PeriodCloseStatus::class,
            'period_start' => 'datetime', 'period_end' => 'datetime', 'closed_at' => 'datetime',
            'revision' => 'integer',
            'gross_cash_collected' => 'decimal:6', 'refunds' => 'decimal:6', 'net_cash' => 'decimal:6', 'gateway_fees' => 'decimal:6',
            'net_cash_after_gateway_fees' => 'decimal:6', 'reconciled_service_cost' => 'decimal:6', 'reconciled_cash_contribution' => 'decimal:6',
            'conditions' => 'array', 'inputs_snapshot' => 'array',
        ];
    }

    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.u';
    }

    /** @return BelongsTo<FinancePeriodCloseScope, $this> */
    public function scope(): BelongsTo
    {
        return $this->belongsTo(FinancePeriodCloseScope::class, 'scope_id');
    }

    /** @return HasMany<FinancePeriodCloseInput, $this> */
    public function inputs(): HasMany
    {
        return $this->hasMany(FinancePeriodCloseInput::class, 'close_id');
    }

    public function month(): string
    {
        return $this->period_start->toImmutable()->utc()->format('Y-m');
    }
}
