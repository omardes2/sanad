<?php

declare(strict_types=1);

namespace App\Services\Reconciliation;

use App\Data\Reconciliation\ScopeSummary;
use App\Enums\CostCoverageStatus;
use App\Enums\CostInvoiceEventType;
use App\Enums\ReconciliationSource;
use App\Models\CostAdjustment;
use App\Models\CostInvoice;
use App\Models\CostInvoiceAllocation;
use App\Models\CostReconciliation;
use App\Models\CostReconciliationScope;
use App\Support\Billing\DecimalMath;
use App\Support\Reconciliation\ReconciliationRules;
use InvalidArgumentException;

/**
 * Reconciled cost per scope (Phase E2) — reads only:
 *  - Base Reconciled Amount (frozen), Σ Adjustments, Adjusted Reconciled Cost;
 *  - the FROZEN calculated snapshot (known amount, rows, coverage);
 *  - Variance vs Known Calculated Cost and the Adjusted Variance, numeric only
 *    when the snapshot coverage was COMPLETE — otherwise UNKNOWN (a
 *    calculated zero without a producer is not a fact);
 *  - LEDGER MOVED SINCE RECONCILIATION when the ledger now hashes differently;
 *  - EVIDENCE VOIDED / SUPERSEDED when an allocated invoice left `confirmed`.
 * Confirmed zero is reported as CONFIRMED ZERO, never as a bare 0.
 * No cash, no contribution, no gross profit (E4+).
 */
final class ReconciledCostQuery
{
    public const MAX_MONTHS = 13;

    public function __construct(private readonly LedgerSnapshotter $ledger) {}

    /**
     * @return list<ScopeSummary> for every scope whose month is in [fromMonth, toMonth]
     */
    public function summarise(string $fromMonth, string $toMonth): array
    {
        [$from] = ReconciliationRules::month($fromMonth);
        [, $to] = ReconciliationRules::month($toMonth);

        if ($to <= $from) {
            throw new InvalidArgumentException('نهاية النطاق يجب أن تكون بعد بدايته.');
        }

        if ($from->diffInMonths($to) > self::MAX_MONTHS) {
            throw new InvalidArgumentException('النطاق الأقصى '.self::MAX_MONTHS.' شهرًا.');
        }

        $scopes = CostReconciliationScope::query()
            ->where('period_start', '>=', $from->format('Y-m-d H:i:s'))->where('period_start', '<', $to->format('Y-m-d H:i:s'))
            ->orderBy('component')->orderBy('counterparty_key')->orderBy('period_start')->orderBy('currency')->get();

        $out = [];

        foreach ($scopes as $scope) {
            $out[] = $this->describe($scope);
        }

        return $out;
    }

    public function describe(CostReconciliationScope $scope): ScopeSummary
    {
        $month = $scope->period_start->toImmutable()->utc()->format('Y-m');
        $current = $scope->current_reconciliation_id === null ? null : CostReconciliation::query()->find($scope->current_reconciliation_id);

        if ($current === null) {
            return new ScopeSummary($scope->id, $scope->component->value, $scope->counterparty_key, $month, $scope->currency, null, null, 'NOT RECONCILED', null, '0.000000', null, null, null, null, null, null, null, null, 'UNKNOWN', false, []);
        }

        $base = CostReconciliationService::scaledOf((string) $current->reconciled_amount);
        $adjustments = DecimalMath::intFromDb(CostAdjustment::query()->where('cost_reconciliation_id', $current->id)->selectRaw('COALESCE(SUM(ROUND(amount * 1000000)), 0) AS s')->value('s'));
        $known = CostReconciliationService::scaledOf((string) $current->calculated_known_amount);
        $coverage = $current->cost_coverage_status;
        $variance = $coverage->allowsVariance();

        $now = $this->ledger->capture($current->component, $current->counterparty_key, $current->period_start->toImmutable(), $current->period_end->toImmutable(), $current->currency);
        $moved = ! hash_equals($current->snapshot_hash, $now->hash());

        $flags = [];

        if ($moved) {
            $flags[] = 'LEDGER MOVED SINCE RECONCILIATION';
        }

        $invoiceIds = CostInvoiceAllocation::query()->where('cost_reconciliation_id', $current->id)->pluck('cost_invoice_id')->unique();

        foreach (CostInvoice::query()->whereIn('id', $invoiceIds)->get() as $invoice) {
            if ($invoice->current_status === CostInvoiceEventType::Voided) {
                $flags[] = 'EVIDENCE VOIDED (#'.$invoice->id.')';
            } elseif ($invoice->current_status === CostInvoiceEventType::Superseded) {
                $flags[] = 'EVIDENCE SUPERSEDED (#'.$invoice->id.' → #'.$invoice->superseded_by_id.')';
            }
        }

        return new ScopeSummary(
            scopeId: $scope->id,
            component: $scope->component->value,
            counterpartyKey: $scope->counterparty_key,
            month: $month,
            currency: $scope->currency,
            reconciliationId: $current->id,
            source: $current->source->value,
            status: $current->source === ReconciliationSource::ConfirmedZero ? 'CONFIRMED ZERO' : 'RECONCILED',
            baseReconciledAmount: ReconciliationRules::format($base),
            adjustments: ReconciliationRules::format($adjustments),
            adjustedReconciledCost: ReconciliationRules::format($base + $adjustments),
            calculatedKnownAmount: ReconciliationRules::format($known),
            calculatedPricedRows: $current->calculated_priced_rows,
            unpricedRows: $current->unpriced_rows,
            currencyMismatchRows: $current->currency_mismatch_rows,
            coverage: $coverage->label(),
            varianceVsKnownCalculated: $variance ? ReconciliationRules::format($base - $known) : null,
            adjustedVarianceVsKnownCalculated: $variance ? ReconciliationRules::format($base + $adjustments - $known) : null,
            varianceStatus: $variance ? 'KNOWN' : ($coverage === CostCoverageStatus::NoProducer ? 'UNKNOWN (NO PRODUCER)' : 'UNKNOWN (PARTIAL CALCULATED COVERAGE)'),
            ledgerMoved: $moved,
            flags: $flags,
        );
    }
}
