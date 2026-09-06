<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CostComponent;
use App\Enums\CostCoverageStatus;
use App\Enums\ReconciliationSource;
use App\Support\Payments\ImmutableFinancialRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One append-only reconciliation with its frozen ledger snapshot (Phase E2). */
class CostReconciliation extends Model
{
    use ImmutableFinancialRecord;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = ['scope_id', 'component', 'counterparty_key', 'period_start', 'period_end', 'currency', 'source', 'reconciled_amount', 'calculated_known_amount', 'calculated_priced_rows', 'unpriced_rows', 'currency_mismatch_rows', 'ledger_max_event_id', 'cost_coverage_status', 'captured_at', 'snapshot_hash', 'supersedes_id', 'reason_code', 'evidence_ref', 'actor_ref', 'created_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'component' => CostComponent::class,
            'source' => ReconciliationSource::class,
            'cost_coverage_status' => CostCoverageStatus::class,
            'reconciled_amount' => 'decimal:6',
            'calculated_known_amount' => 'decimal:6',
            'calculated_priced_rows' => 'integer',
            'unpriced_rows' => 'integer',
            'currency_mismatch_rows' => 'integer',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'captured_at' => 'datetime',
        ];
    }

    public function getDateFormat(): string
    {
        return CostInvoice::TIMESTAMP_FORMAT;
    }

    /** @return BelongsTo<CostReconciliationScope, $this> */
    public function scope(): BelongsTo
    {
        return $this->belongsTo(CostReconciliationScope::class, 'scope_id');
    }

    /** @return HasMany<CostInvoiceAllocation, $this> */
    public function evidence(): HasMany
    {
        return $this->hasMany(CostInvoiceAllocation::class);
    }

    /** @return HasMany<CostAdjustment, $this> */
    public function adjustments(): HasMany
    {
        return $this->hasMany(CostAdjustment::class);
    }
}
