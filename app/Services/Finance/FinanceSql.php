<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\CostSource;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Driver-specific SQL fragments for the financial aggregations, so every
 * finance figure is computed the SAME way on PostgreSQL (production) and
 * SQLite (fast suite) and never passes through PHP floats:
 *
 *  - money is summed as SCALED INTEGERS (amount × 10^6, the ledger scale)
 *    inside the database and parsed back with DecimalMath::intFromDb();
 *  - date buckets are UTC calendar strings (occurred_at is stored in UTC).
 *
 * Only PostgreSQL and SQLite are supported; any other driver is refused
 * loudly rather than silently approximated.
 */
final class FinanceSql
{
    public const LEDGER_SCALE = 6;

    public function __construct(private readonly Connection $connection) {}

    public function driver(): string
    {
        $driver = $this->connection->getDriverName();

        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException("Finance aggregations support pgsql and sqlite only; got [{$driver}].");
        }

        return $driver;
    }

    /**
     * SUM of a decimal(…,6) column as an integer at scale 6, restricted to rows
     * matching $where (SQL boolean expression). Rows outside $where add 0.
     */
    public function scaledSum(string $column, string $where): string
    {
        $factor = '1000000';

        $scaled = match ($this->driver()) {
            'pgsql' => "ROUND({$column} * {$factor})::bigint",
            'sqlite' => "CAST(ROUND({$column} * {$factor}) AS INTEGER)",
        };

        return "COALESCE(SUM(CASE WHEN {$where} THEN {$scaled} ELSE 0 END), 0)";
    }

    /** SUM of an integer column restricted to $where. */
    public function countWhere(string $where): string
    {
        return "COALESCE(SUM(CASE WHEN {$where} THEN 1 ELSE 0 END), 0)";
    }

    /** SUM of an integer column restricted to $where. */
    public function sumWhere(string $column, string $where): string
    {
        return "COALESCE(SUM(CASE WHEN {$where} THEN {$column} ELSE 0 END), 0)";
    }

    /** UTC calendar bucket ("YYYY-MM-DD" for day, "YYYY-MM" for month). */
    public function dateBucket(string $column, string $granularity): string
    {
        $format = match ($granularity) {
            'day' => ['pgsql' => 'YYYY-MM-DD', 'sqlite' => '%Y-%m-%d'],
            'month' => ['pgsql' => 'YYYY-MM', 'sqlite' => '%Y-%m'],
            default => throw new RuntimeException("Unknown granularity [{$granularity}]."),
        };

        return match ($this->driver()) {
            'pgsql' => "to_char({$column}, '{$format['pgsql']}')",
            'sqlite' => "strftime('{$format['sqlite']}', {$column})",
        };
    }

    /** SQL predicate: the row's cost is KNOWN (mirrors UsageEvent::scopePriced). */
    public function pricedPredicate(): string
    {
        return "cost_source IN ('".CostSource::ModelPrice->value."', '".CostSource::ConfigRate->value."')";
    }

    /** SQL predicate: the row is UNPRICED (mirrors UsageEvent::scopeUnpriced). */
    public function unpricedPredicate(): string
    {
        $unknown = implode("', '", CostSource::unknownValues());

        return "(cost_source IS NULL OR cost_source IN ('{$unknown}'))";
    }
}
