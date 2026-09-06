<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Payments\ImmutableFinancialRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Signed, append-only evidence share of one invoice line in one reconciliation (Phase E2). */
class CostInvoiceAllocation extends Model
{
    use ImmutableFinancialRecord;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = ['cost_invoice_id', 'cost_invoice_line_id', 'cost_reconciliation_id', 'amount', 'currency', 'actor_ref', 'created_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'decimal:6'];
    }

    /** @return BelongsTo<CostInvoiceLine, $this> */
    public function line(): BelongsTo
    {
        return $this->belongsTo(CostInvoiceLine::class, 'cost_invoice_line_id');
    }

    /** @return BelongsTo<CostReconciliation, $this> */
    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(CostReconciliation::class, 'cost_reconciliation_id');
    }
}
