<?php

declare(strict_types=1);

namespace App\Services\Reconciliation;

use App\Data\Reconciliation\LedgerSnapshot;
use App\Enums\CostComponent;
use App\Enums\CostCoverageStatus;
use App\Models\UsageEvent;
use App\Services\Finance\FinanceSql;
use App\Support\Billing\DecimalMath;
use Carbon\CarbonImmutable;

/**
 * Reads the calculated ledger for one scope with scaled-integer sums inside
 * SQL (identical on PostgreSQL and SQLite). Called under the scope lock at
 * reconciliation time (the frozen snapshot) and again by the query (to
 * detect a ledger that moved since). Never writes.
 *
 * Scope rows: occurred_at in [start, end); for the provider component only
 * rows whose `provider` equals the counterparty key. Known amount = Σ of the
 * component column over PRICED rows in the scope currency; priced rows in
 * another currency are counted as mismatches, unpriced rows are counted, and
 * neither is ever summed as cost.
 */
final class LedgerSnapshotter
{
    public function __construct(private readonly FinanceSql $sql) {}

    public function capture(CostComponent $component, string $counterpartyKey, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, string $currency): LedgerSnapshot
    {
        $this->sql->driver();
        $column = $component->ledgerColumn();
        $priced = $this->sql->pricedPredicate();
        $unpriced = $this->sql->unpricedPredicate();
        $currencyMatch = 'currency = ?';

        // The ledger stores occurred_at at second precision: bind second-precision
        // bounds so the text comparison on SQLite matches PostgreSQL exactly.
        $query = UsageEvent::query()->toBase()
            ->where('occurred_at', '>=', $periodStart->utc()->format('Y-m-d H:i:s'))
            ->where('occurred_at', '<', $periodEnd->utc()->format('Y-m-d H:i:s'));

        if ($component === CostComponent::Provider) {
            $query->where('provider', $counterpartyKey);
        }

        $row = $query->selectRaw(implode(', ', [
            $this->sql->scaledSum($column, "{$priced} AND {$currencyMatch}").' AS known',
            $this->sql->countWhere("{$priced} AND {$currencyMatch}").' AS priced_rows',
            $this->sql->countWhere($unpriced).' AS unpriced_rows',
            $this->sql->countWhere("{$priced} AND NOT ({$currencyMatch})").' AS mismatch_rows',
            'MAX(id) AS max_id',
        ]), [$currency, $currency, $currency])->first();

        $unpricedRows = DecimalMath::intFromDb($row->unpriced_rows);
        $mismatchRows = DecimalMath::intFromDb($row->mismatch_rows);

        $coverage = match (true) {
            ! $component->hasProducer() => CostCoverageStatus::NoProducer,
            $unpricedRows > 0 || $mismatchRows > 0 => CostCoverageStatus::Partial,
            default => CostCoverageStatus::Complete,
        };

        return new LedgerSnapshot(
            component: $component,
            counterpartyKey: $counterpartyKey,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            currency: $currency,
            knownScaled: DecimalMath::intFromDb($row->known),
            pricedRows: DecimalMath::intFromDb($row->priced_rows),
            unpricedRows: $unpricedRows,
            currencyMismatchRows: $mismatchRows,
            maxEventId: $row->max_id === null ? null : DecimalMath::intFromDb($row->max_id),
            coverage: $coverage,
            capturedAt: CarbonImmutable::now('UTC'),
        );
    }
}
