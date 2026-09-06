<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CloseInputType;
use App\Support\Payments\ImmutableFinancialRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One immutable drill-down row of a close's canonical inputs snapshot (Phase E4). Never a source of truth. */
class FinancePeriodCloseInput extends Model
{
    use ImmutableFinancialRecord;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = ['close_id', 'input_type', 'input_id', 'amount', 'currency', 'scale', 'reporting_amount', 'reporting_currency', 'status', 'fx_conversion_id', 'fx_rate_id', 'fx_rate_snapshot', 'fx_direction', 'flags', 'created_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['input_type' => CloseInputType::class, 'amount' => 'decimal:6', 'reporting_amount' => 'decimal:6', 'fx_rate_snapshot' => 'decimal:12', 'flags' => 'array', 'scale' => 'integer', 'input_id' => 'integer'];
    }

    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.u';
    }

    /** @return BelongsTo<FinancePeriodClose, $this> */
    public function close(): BelongsTo
    {
        return $this->belongsTo(FinancePeriodClose::class, 'close_id');
    }
}
