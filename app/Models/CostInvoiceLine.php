<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CostInvoiceLineKind;
use App\Support\Payments\ImmutableFinancialRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One signed, append-only invoice line (Phase E2). */
class CostInvoiceLine extends Model
{
    use ImmutableFinancialRecord;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = ['cost_invoice_id', 'line_no', 'kind', 'description_code', 'amount', 'currency', 'period_start', 'period_end', 'actor_ref', 'created_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['kind' => CostInvoiceLineKind::class, 'amount' => 'decimal:6', 'period_start' => 'datetime', 'period_end' => 'datetime'];
    }

    /** @return BelongsTo<CostInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CostInvoice::class, 'cost_invoice_id');
    }

    /** @return HasMany<CostInvoiceAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(CostInvoiceAllocation::class);
    }
}
