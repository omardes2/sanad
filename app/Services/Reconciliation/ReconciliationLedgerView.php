<?php

declare(strict_types=1);

namespace App\Services\Reconciliation;

use App\Enums\CostInvoiceEventType;
use App\Enums\CostInvoiceLineKind;
use App\Models\CostAdjustment;
use App\Models\CostInvoice;
use App\Models\CostInvoiceAllocation;
use App\Models\CostInvoiceLine;
use App\Models\FxRate;
use App\Services\Fx\FxRateBook;
use App\Support\Billing\DecimalMath;
use App\Support\Reconciliation\ReconciliationRules;
use Illuminate\Support\Collection;

/**
 * Display-only sums for the E5.2b pages — the SAME scaled sums the E2 services
 * cap against (integer scale 6, ROUND(x * 1000000) in SQL), keyed per id so a
 * page issues one query per figure whatever the number of rows. Never used to
 * clip, decide or write: the services re-check everything under their locks.
 */
final class ReconciliationLedgerView
{
    public function __construct(private readonly FxRateBook $rates) {}

    /**
     * Σ SOURCE share attributed on each invoice line across ALL reconciliations (the cap the service enforces).
     *
     * @param  list<int>  $lineIds
     * @return array<int, int> line id ⇒ scaled (6) signed sum
     */
    public function lineAllocated(array $lineIds): array
    {
        if ($lineIds === []) {
            return [];
        }

        return CostInvoiceAllocation::query()->whereIn('cost_invoice_line_id', $lineIds)
            ->selectRaw('cost_invoice_line_id AS id, COALESCE(SUM(ROUND(COALESCE(source_amount, amount) * 1000000)), 0) AS s')->groupBy('cost_invoice_line_id')
            ->get()->mapWithKeys(fn ($r) => [(int) $r->id => DecimalMath::intFromDb($r->s)])->all();
    }

    /**
     * Σ adjustments per reconciliation (Adjusted Reconciled Cost = Base + this).
     *
     * @param  list<int>  $reconciliationIds
     * @return array<int, int> reconciliation id ⇒ scaled (6) signed sum
     */
    public function adjustments(array $reconciliationIds): array
    {
        if ($reconciliationIds === []) {
            return [];
        }

        return CostAdjustment::query()->whereIn('cost_reconciliation_id', $reconciliationIds)
            ->selectRaw('cost_reconciliation_id AS id, COALESCE(SUM(ROUND(amount * 1000000)), 0) AS s')->groupBy('cost_reconciliation_id')
            ->get()->mapWithKeys(fn ($r) => [(int) $r->id => DecimalMath::intFromDb($r->s)])->all();
    }

    /**
     * Evidence lines a scope may draw on — exactly what the service accepts:
     * CONFIRMED invoices of the same component / counterparty, allocatable
     * kinds only (service / credit), with the remaining source share.
     *
     * @return Collection<int, array{line: CostInvoiceLine, invoice: CostInvoice, allocated: int, remaining: int, sign: string}>
     */
    public function eligibleEvidence(string $component, string $counterparty): Collection
    {
        $invoices = CostInvoice::query()->where('component', $component)->where('counterparty_key', $counterparty)
            ->where('current_status', CostInvoiceEventType::Confirmed->value)->orderByDesc('id')->limit(50)->get()->keyBy('id');

        if ($invoices->isEmpty()) {
            return collect();
        }

        $lines = CostInvoiceLine::query()->whereIn('cost_invoice_id', $invoices->keys()->all())
            ->whereIn('kind', array_map(static fn (CostInvoiceLineKind $k): string => $k->value, array_filter(CostInvoiceLineKind::cases(), static fn (CostInvoiceLineKind $k): bool => $k->isAllocatable())))
            ->orderByDesc('cost_invoice_id')->orderBy('line_no')->get();
        $allocated = $this->lineAllocated($lines->pluck('id')->all());

        return $lines->map(function (CostInvoiceLine $line) use ($invoices, $allocated): array {
            $amount = CostReconciliationService::scaledOf((string) $line->amount);
            $used = $allocated[$line->id] ?? 0;

            return [
                'line' => $line,
                'invoice' => $invoices->get($line->cost_invoice_id),
                'allocated' => $used,
                'remaining' => $amount < 0 ? -max(0, abs($amount) - abs($used)) : max(0, $amount - $used),
                'sign' => $amount < 0 ? 'negative (credit)' : 'positive',
            ];
        })->values();
    }

    /**
     * Quotes the user may pick from for a cross-currency evidence line — ONLY
     * the current revision dated on the invoice's issued_at. Never auto-selected.
     *
     * @return list<FxRate>
     */
    public function quotesFor(CostInvoice $invoice, string $scopeCurrency): array
    {
        if ($invoice->currency === $scopeCurrency) {
            return [];
        }

        return $this->rates->quotesFor($invoice->currency, $scopeCurrency, $invoice->issued_at->utc()->format('Y-m-d'));
    }

    public static function money(int $scaled): string
    {
        return ReconciliationRules::format($scaled);
    }
}
