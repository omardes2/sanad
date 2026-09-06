<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\Payments\ImmutableFinancialRecordException;
use Illuminate\Database\Eloquent\Model;

/** Revision scope of one reporting conversion: lock target + current pointer (Phase E3). */
class FxConversionScope extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['subject_type', 'subject_id', 'purpose', 'target_currency', 'current_conversion_id', 'version', 'updated_by_ref'];

    protected static function booted(): void
    {
        static::updating(static function (self $scope): void {
            foreach (['subject_type', 'subject_id', 'purpose', 'target_currency'] as $attribute) {
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
        return ['version' => 'integer', 'subject_id' => 'integer'];
    }

    public function stateToken(): string
    {
        return 'c:'.($this->current_conversion_id ?? 0);
    }
}
