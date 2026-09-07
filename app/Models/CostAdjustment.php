<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Payments\ImmutableFinancialRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One signed, append-only post-reconciliation correction (Phase E2). */
class CostAdjustment extends Model
{
    use ImmutableFinancialRecord;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = ['cost_reconciliation_id', 'amount', 'currency', 'reason_code', 'evidence_ref', 'actor_ref', 'idempotency_key', 'created_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'decimal:6'];
    }

    /** @return BelongsTo<CostReconciliation, $this> */
    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(CostReconciliation::class, 'cost_reconciliation_id');
    }
}
