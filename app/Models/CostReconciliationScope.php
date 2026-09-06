<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CostComponent;
use App\Exceptions\Payments\ImmutableFinancialRecordException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The reconciliation scope projection (Phase E2): the FOR UPDATE target and
 * the pointer to the current reconciliation. Only the pointer / version /
 * updated_by_ref move, only through CostReconciliationService; the scope
 * identity is fixed and the row is never deleted.
 */
class CostReconciliationScope extends Model
{
    public const IMMUTABLE = ['component', 'counterparty_key', 'period_start', 'period_end', 'currency'];

    /**
     * @var list<string>
     */
    protected $fillable = ['component', 'counterparty_key', 'period_start', 'period_end', 'currency', 'current_reconciliation_id', 'version', 'updated_by_ref'];

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
        return ['component' => CostComponent::class, 'period_start' => 'datetime', 'period_end' => 'datetime', 'version' => 'integer'];
    }

    /** @return BelongsTo<CostReconciliation, $this> */
    public function current(): BelongsTo
    {
        return $this->belongsTo(CostReconciliation::class, 'current_reconciliation_id');
    }

    /** What a caller must present to act on this scope: the current pointer. */
    public function stateToken(): string
    {
        return 'r:'.($this->current_reconciliation_id ?? 0);
    }
}
