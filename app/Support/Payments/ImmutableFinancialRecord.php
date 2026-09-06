<?php

declare(strict_types=1);

namespace App\Support\Payments;

use App\Exceptions\Payments\ImmutableFinancialRecordException;

/**
 * An append-only financial record: the application can create it and read
 * it, never update or delete it. Any attempt throws — history is never
 * rewritten; corrections are new rows (refunds, allocations, adjustments).
 */
trait ImmutableFinancialRecord
{
    public static function bootImmutableFinancialRecord(): void
    {
        static::updating(static function ($model): void {
            throw ImmutableFinancialRecordException::for($model, 'update');
        });

        static::deleting(static function ($model): void {
            throw ImmutableFinancialRecordException::for($model, 'delete');
        });
    }
}
