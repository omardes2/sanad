<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FxConversionPurpose;
use App\Enums\FxDirection;
use App\Support\Fx\FxMath;
use App\Support\Payments\ImmutableFinancialRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One frozen, append-only reporting conversion revision (Phase E3). */
class FxConversion extends Model
{
    use ImmutableFinancialRecord;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = ['scope_id', 'subject_type', 'subject_id', 'purpose', 'subject_date', 'source_amount', 'source_scale', 'source_currency', 'fx_rate_id', 'fx_rate_date', 'rate_snapshot', 'direction', 'target_amount', 'target_scale', 'target_currency', 'supersedes_id', 'reason_code', 'actor_ref', 'created_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => FxConversionPurpose::class,
            'direction' => FxDirection::class,
            'subject_date' => 'datetime',
            'fx_rate_date' => 'date:Y-m-d',
            'source_amount' => 'decimal:6',
            'target_amount' => 'decimal:6',
            'rate_snapshot' => 'decimal:12',
            'source_scale' => 'integer',
            'target_scale' => 'integer',
            'subject_id' => 'integer',
        ];
    }

    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.u';
    }

    /** @return BelongsTo<FxRate, $this> */
    public function rate(): BelongsTo
    {
        return $this->belongsTo(FxRate::class, 'fx_rate_id');
    }

    /** The target amount at its own scale (no trailing scale-6 noise for cash). */
    public function targetAmountAtScale(): string
    {
        return FxMath::formatAtScale((string) $this->target_amount, $this->target_scale);
    }

    public function sourceAmountAtScale(): string
    {
        return FxMath::formatAtScale((string) $this->source_amount, $this->source_scale);
    }
}
