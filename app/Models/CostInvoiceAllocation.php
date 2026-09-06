<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FxDirection;
use App\Support\Payments\ImmutableFinancialRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Signed, append-only evidence share of one invoice line in one
 * reconciliation (Phase E2). Phase E3: `source_amount` / `source_currency`
 * are the share in the line's currency (the cap column), `amount` is the
 * value in the scope currency; a cross-currency share freezes its own
 * fx_rate_id / snapshot / direction / rate date (NULL = NATIVE).
 */
class CostInvoiceAllocation extends Model
{
    use ImmutableFinancialRecord;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = ['cost_invoice_id', 'cost_invoice_line_id', 'cost_reconciliation_id', 'amount', 'source_amount', 'source_currency', 'currency', 'fx_rate_id', 'fx_rate_snapshot', 'fx_direction', 'fx_rate_date', 'actor_ref', 'created_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'decimal:6', 'source_amount' => 'decimal:6', 'fx_rate_snapshot' => 'decimal:12', 'fx_direction' => FxDirection::class, 'fx_rate_date' => 'date:Y-m-d'];
    }

    public function fxStatus(): string
    {
        return $this->fx_rate_id === null ? 'NATIVE' : 'CONVERTED';
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
