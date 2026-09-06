<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\Payments\ImmutableFinancialRecordException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The close projection per (month UTC, reporting currency): state + pointer + version, moved only by PeriodCloseService (Phase E4). */
class FinancePeriodCloseScope extends Model
{
    public const IMMUTABLE = ['period_start', 'period_end', 'reporting_currency'];

    /**
     * @var list<string>
     */
    protected $fillable = ['period_start', 'period_end', 'reporting_currency', 'state', 'current_close_id', 'version', 'updated_by_ref'];

    protected static function booted(): void
    {
        static::updating(static function (self $scope): void {
            foreach (self::IMMUTABLE as $attribute) {
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
        return ['period_start' => 'datetime', 'period_end' => 'datetime', 'version' => 'integer'];
    }

    /** @return BelongsTo<FinancePeriodClose, $this> */
    public function current(): BelongsTo
    {
        return $this->belongsTo(FinancePeriodClose::class, 'current_close_id');
    }

    public function isClosed(): bool
    {
        return $this->state === 'closed';
    }

    public function stateToken(): string
    {
        return 'p:'.($this->current_close_id ?? 0);
    }

    public function month(): string
    {
        return $this->period_start->toImmutable()->utc()->format('Y-m');
    }
}
