<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\Payments\ImmutableFinancialRecordException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Revision scope of one (pair, rate_date) quote: the lock target and current pointer (Phase E3). */
class FxRateScope extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['fx_pair_id', 'rate_date', 'current_rate_id', 'version', 'updated_by_ref'];

    protected static function booted(): void
    {
        static::updating(static function (self $scope): void {
            foreach (['fx_pair_id', 'rate_date'] as $attribute) {
                if ($scope->isDirty($attribute)) {
                    throw ImmutableFinancialRecordException::for($scope, "update of scope identity [{$attribute}]");
                }
            }
        });

        static::deleting(static function (self $scope): void {
            throw ImmutableFinancialRecordException::for($scope, 'delete');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['rate_date' => 'date:Y-m-d', 'version' => 'integer'];
    }

    /** @return BelongsTo<FxPair, $this> */
    public function pair(): BelongsTo
    {
        return $this->belongsTo(FxPair::class, 'fx_pair_id');
    }

    public function stateToken(): string
    {
        return 'x:'.($this->current_rate_id ?? 0);
    }
}
