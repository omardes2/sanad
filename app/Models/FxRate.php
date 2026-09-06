<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Payments\ImmutableFinancialRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One append-only manual quote revision: 1 base = rate × quote on rate_date (Phase E3). */
class FxRate extends Model
{
    use ImmutableFinancialRecord;

    public const UPDATED_AT = null;

    public const SCALE = 12;

    /**
     * @var list<string>
     */
    protected $fillable = ['fx_pair_id', 'scope_id', 'rate_date', 'base_currency', 'quote_currency', 'rate', 'source', 'evidence_ref', 'reason_code', 'supersedes_id', 'recorded_by_ref', 'created_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['rate_date' => 'date:Y-m-d', 'rate' => 'decimal:12'];
    }

    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.u';
    }

    /** @return BelongsTo<FxPair, $this> */
    public function pair(): BelongsTo
    {
        return $this->belongsTo(FxPair::class, 'fx_pair_id');
    }

    /** @return BelongsTo<FxRateScope, $this> */
    public function scope(): BelongsTo
    {
        return $this->belongsTo(FxRateScope::class, 'scope_id');
    }

    public function rateDate(): string
    {
        return $this->rate_date->format('Y-m-d');
    }
}
