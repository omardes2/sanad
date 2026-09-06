<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\Reconciliation\CostInvoiceInput;
use App\Data\Reconciliation\EvidenceAllocation;
use App\Data\Reconciliation\ReconciliationInput;
use App\Exceptions\Reconciliation\ReconciliationConflictException;
use App\Exceptions\Reconciliation\ReconciliationRuleException;
use App\Exceptions\Reconciliation\StaleReconciliationException;
use App\Services\Reconciliation\CostInvoiceService;
use App\Services\Reconciliation\CostReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Testing-only probe (Phase E2): performs ONE operation and prints a single
 * machine-readable line. Launched concurrently by the PostgreSQL race tests.
 *
 *  record-invoice <component> <counterparty> <key> <total> <currency> [invoice_ref]
 *      → created:<id> | existing:<id> | conflict
 *  confirm <invoice> <expected_token>
 *      → ok:<id> | stale | rejected:<rule>
 *  reconcile <component> <counterparty> <YYYY-MM> <currency> <expected|none> <line_id>:<amount>[:<fx_rate_id>][,…]
 *      → ok:<reconciliation_id> | stale | rejected:<rule>
 *  zero <component> <counterparty> <YYYY-MM> <currency> <expected|none>
 *      → ok:<reconciliation_id> | stale | rejected:<rule>
 */
class ReconciliationProbe extends Command
{
    protected $signature = 'sanad:reconciliation-probe {op} {args*}';

    protected $description = 'Testing only: perform one invoice / reconciliation operation and print the outcome';

    protected $hidden = true;

    public function handle(CostInvoiceService $invoices, CostReconciliationService $reconciliations): int
    {
        /** @var list<string> $a */
        $a = $this->argument('args');

        try {
            $line = match ((string) $this->argument('op')) {
                'record-invoice' => $this->recordInvoice($invoices, $a),
                'confirm' => 'ok:'.$invoices->confirm((int) $a[0], $a[1])->id,
                'reconcile' => 'ok:'.$reconciliations->reconcile(new ReconciliationInput(
                    component: $a[0], counterpartyKey: $a[1], month: $a[2], currency: $a[3],
                    expectedCurrentReconciliationId: $a[4] === 'none' ? null : (int) $a[4],
                    source: 'invoice',
                    allocations: array_map(static function (string $triple): EvidenceAllocation {
                        $parts = explode(':', $triple, 3);

                        return new EvidenceAllocation((int) $parts[0], $parts[1], isset($parts[2]) && $parts[2] !== '' ? (int) $parts[2] : null);
                    }, explode(',', $a[5])),
                    reasonCode: 'probe',
                ))->id,
                'zero' => 'ok:'.$reconciliations->reconcile(new ReconciliationInput(
                    component: $a[0], counterpartyKey: $a[1], month: $a[2], currency: $a[3],
                    expectedCurrentReconciliationId: $a[4] === 'none' ? null : (int) $a[4],
                    source: 'confirmed_zero', reasonCode: 'probe', evidenceRef: 'probe:zero', typedConfirmation: 'ZERO',
                ))->id,
                default => throw new \InvalidArgumentException('Unknown op'),
            };
        } catch (ReconciliationRuleException $e) {
            $line = 'rejected:'.$e->rule;
        } catch (StaleReconciliationException) {
            $line = 'stale';
        } catch (ReconciliationConflictException) {
            $line = 'conflict';
        }

        $this->line($line);

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $a
     */
    private function recordInvoice(CostInvoiceService $invoices, array $a): string
    {
        $invoice = $invoices->recordDraft(new CostInvoiceInput(
            component: $a[0], counterpartyKey: $a[1], idempotencyKey: $a[2],
            issuedAt: CarbonImmutable::parse('2026-09-01', 'UTC'),
            periodStart: CarbonImmutable::parse('2026-08-01', 'UTC'), periodEnd: CarbonImmutable::parse('2026-09-01', 'UTC'),
            currency: $a[4], totalAmount: $a[3], invoiceRef: $a[5] ?? null,
        ));

        return ($invoice->wasRecentlyCreated ? 'created:' : 'existing:').$invoice->id;
    }
}
