<?php

declare(strict_types=1);

use App\Livewire\Dashboard\Finance\CostInvoiceDetail;
use App\Livewire\Dashboard\Finance\CostInvoices;
use App\Livewire\Dashboard\Finance\Reconciliation;
use App\Livewire\Dashboard\Finance\ReconciliationScopeDetail;
use App\Models\CostReconciliationScope;
use App\Support\Rbac\Role;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Phase E5.2b — render = read-only, at the wire: the invoice list (filters,
 * pagination), the invoice detail, the scope list (filters, pagination, CHECK
 * LEDGER), the scope detail (existing and new) and every confirmation panel
 * issue NO INSERT / UPDATE / DELETE / DDL on any table. Writes happen only
 * after an explicit action submit, through the E2 services.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));
});

it('issues no write statement while rendering, filtering, paginating, opening details, running CHECK LEDGER and opening every confirmation panel', function () {
    $fx = closableMonth();
    $finance = userWithRole(Role::Finance);
    $scope = CostReconciliationScope::query()->findOrFail($fx['reconciliation']->scope_id);
    $this->actingAs($finance);
    $writes = [];
    DB::listen(function (QueryExecuted $q) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|replace|truncate|alter|create|drop)\b/i', $q->sql) === 1) {
            $writes[] = $q->sql;
        }
    });

    $this->get(route('dashboard.finance.cost_invoices', ['fromMonth' => '2026-08', 'toMonth' => '2026-08']))->assertOk();
    Livewire::actingAs($finance)->test(CostInvoices::class, ['fromMonth' => '2026-08', 'toMonth' => '2026-08'])->set('status', 'confirmed')->set('component', 'provider')->set('ref', 'x')->call('gotoPage', 2)->call('gotoPage', 1)->assertOk();
    $this->get(route('dashboard.finance.cost_invoices.show', $fx['invoice']->id))->assertOk();
    Livewire::actingAs($finance)->test(CostInvoiceDetail::class, ['invoice' => $fx['invoice']])->call('openConfirm', 'line')->call('openConfirm', 'confirm')->call('openConfirm', 'void')->call('openConfirm', 'supersede')->call('closeConfirm')->assertOk();
    $this->get(route('dashboard.finance.reconciliation', ['fromMonth' => '2026-08', 'toMonth' => '2026-08']))->assertOk();
    Livewire::actingAs($finance)->test(Reconciliation::class, ['fromMonth' => '2026-08', 'toMonth' => '2026-08'])->set('status', 'reconciled')->call('gotoPage', 1)->call('checkLedger', $scope->id)->call('checkLedger', $fx['external']->scope_id)->assertOk();
    $this->get(route('dashboard.finance.reconciliation.show', $scope->id))->assertOk();
    Livewire::actingAs($finance)->test(ReconciliationScopeDetail::class, ['scope' => $scope])->call('openConfirm', 'evidence')->call('addEvidenceRow')->call('removeEvidenceRow', 1)->call('openConfirm', 'manual')->call('openConfirm', 'zero')->call('openConfirm', 'adjust')->call('closeConfirm')->assertOk();
    $this->get(route('dashboard.finance.reconciliation.new', ['component' => 'external', 'counterparty' => 'vendor-x', 'month' => '2026-07', 'currency' => 'USD']))->assertOk();
    Livewire::withQueryParams(['component' => 'external', 'counterparty' => 'vendor-x', 'month' => '2026-07', 'currency' => 'USD'])->actingAs($finance)->test(ReconciliationScopeDetail::class)->call('openConfirm', 'evidence')->call('openConfirm', 'zero')->assertOk();

    expect($writes)->toBe([]);
});

it('source level: the E5.2b pages call no model write and no query-builder write; every write goes through the E2 services', function () {
    foreach (['Livewire/Dashboard/Finance/CostInvoices.php', 'Livewire/Dashboard/Finance/CostInvoiceDetail.php', 'Livewire/Dashboard/Finance/Reconciliation.php', 'Livewire/Dashboard/Finance/ReconciliationScopeDetail.php', 'Livewire/Dashboard/Finance/Concerns/HandlesFinanceActions.php', 'Livewire/Dashboard/Finance/Concerns/HandlesReconciliationActions.php', 'Services/Reconciliation/ReconciliationLedgerView.php'] as $file) {
        $src = php_strip_whitespace(app_path($file));
        expect(preg_match('/->(create|update|delete|save|insert|forceFill|upsert|increment|decrement|touch|truncate)\(/', $src))->toBe(0, $file)
            ->and(preg_match('/DB::(statement|unprepared|insert|update|delete|table)/', $src))->toBe(0, $file);
    }
    $invoiceDetail = php_strip_whitespace(app_path('Livewire/Dashboard/Finance/CostInvoiceDetail.php'));
    $scopeDetail = php_strip_whitespace(app_path('Livewire/Dashboard/Finance/ReconciliationScopeDetail.php'));
    $list = php_strip_whitespace(app_path('Livewire/Dashboard/Finance/CostInvoices.php'));
    expect(preg_match_all('/\$service->(addLine|confirm|void|supersede)\(/', $invoiceDetail))->toBe(4)
        ->and(preg_match_all('/\$service->(reconcile|adjust)\(/', $scopeDetail))->toBe(2)
        ->and(preg_match_all('/\$service->recordDraft\(/', $list))->toBe(1)
        ->and(preg_match('/LedgerSnapshotter|->capture\(/', php_strip_whitespace(app_path('Livewire/Dashboard/Finance/Reconciliation.php'))))->toBe(0); // the list never captures the ledger itself; CHECK LEDGER delegates to describe()
});
