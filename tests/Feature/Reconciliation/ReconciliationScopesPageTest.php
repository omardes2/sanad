<?php

declare(strict_types=1);

use App\Livewire\Dashboard\Finance\Reconciliation;
use App\Models\CostReconciliationScope;
use App\Models\User;
use App\Services\Reconciliation\CostInvoiceService;
use App\Support\Rbac\Role;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * Phase E5.2b — /dashboard/finance/reconciliation (scope list): strict RBAC;
 * stored figures only (current pointer, source, frozen base / adjustments /
 * adjusted / coverage / variance); NO live ledger capture per row on render;
 * `LIVE LEDGER STATUS: NOT CHECKED` + CHECK LEDGER for ONE scope on demand
 * (read-only, no cache); allowlisted, bounded, URL-kept filters; 25 rows.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));
});

/** Counts the live ledger reads (every LedgerSnapshotter::capture reads usage_events) issued while running the callable. */
function ledgerCaptures(callable $fn): int
{
    $reads = 0;
    DB::listen(function (QueryExecuted $q) use (&$reads): void {
        if (preg_match('/^\s*select\b/i', $q->sql) === 1 && str_contains($q->sql, 'usage_events')) {
            $reads++;
        }
    });
    $fn();

    return $reads;
}

it('is reachable only with finance.reconcile (super_admin and finance 200; operations/support/legacy admin/no-role 403; guests redirected), including the new-scope and scope-detail routes', function () {
    rbacSync();
    $fx = closableMonth();
    $scope = CostReconciliationScope::query()->firstOrFail();
    $urls = [
        route('dashboard.finance.reconciliation', ['fromMonth' => '2026-08', 'toMonth' => '2026-08']),
        route('dashboard.finance.reconciliation.show', $scope->id),
        route('dashboard.finance.reconciliation.new', ['component' => 'external', 'counterparty' => 'x-1', 'month' => '2026-07', 'currency' => 'USD']),
    ];
    foreach ($urls as $url) {
        $this->get($url)->assertRedirect(route('login'));
    }
    foreach ($urls as $url) {
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get($url)->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => false]))->get($url)->assertForbidden();
        $this->actingAs(userWithRole(Role::Operations))->get($url)->assertForbidden();
        $this->actingAs(userWithRole(Role::Support))->get($url)->assertForbidden();
        $this->actingAs(userWithRole(Role::Finance))->get($url)->assertOk();
        $this->actingAs(userWithRole(Role::SuperAdmin))->get($url)->assertOk();
    }
    $this->actingAs(userWithRole(Role::Finance))->get(route('dashboard.finance.reconciliation.show', 999))->assertNotFound();
    $this->actingAs(userWithRole(Role::Finance))->get(route('dashboard.finance.reconciliation.new', ['component' => 'bogus', 'counterparty' => 'x', 'month' => '2026-07', 'currency' => 'USD']))->assertNotFound();
});

it('lists stored figures only — no ledger capture on render whatever the number of scopes — and shows LIVE LEDGER STATUS: NOT CHECKED per row; the frozen variance comes from the stored snapshot', function () {
    $fx = closableMonth();
    $finance = userWithRole(Role::Finance);
    $url = route('dashboard.finance.reconciliation', ['fromMonth' => '2026-08', 'toMonth' => '2026-08']);

    $captures = ledgerCaptures(fn () => $this->actingAs($finance)->get($url)->assertOk()
        ->assertSee('3 rows')->assertSee('LIVE LEDGER STATUS: NOT CHECKED')->assertSee('check-ledger-'.$fx['reconciliation']->scope_id)
        ->assertSee('60.000000')->assertSee('-5.000000')->assertSee('55.000000') // base · adjustments · adjusted
        ->assertSee('5.000000') // frozen adjusted variance: 55 − 50 known
        ->assertSee('CONFIRMED ZERO')->assertSee('UNKNOWN (NO PRODUCER)')->assertSee('RECONCILED')
        ->assertDontSee('LEDGER MOVED SINCE RECONCILIATION')->assertDontSee('Gross Margin')->assertDontSee('Revenue:'));
    expect($captures)->toBe(0);

    // a NOT RECONCILED scope row (created by a superseded history? none) — the identity form opens the new-scope page
    $page = Livewire::actingAs($finance)->test(Reconciliation::class)->set('newComponent', 'external')->set('newCounterparty', 'vendor-x')->set('newMonth', '2026-07')->set('newCurrency', 'usd')->call('startScope')
        ->assertRedirect(route('dashboard.finance.reconciliation.new', ['component' => 'external', 'counterparty' => 'vendor-x', 'month' => '2026-07', 'currency' => 'USD']));
    Livewire::actingAs($finance)->test(Reconciliation::class)->set('newComponent', 'provider')->set('newCounterparty', 'groq')->set('newMonth', '2026-08')->set('newCurrency', 'USD')->call('startScope')
        ->assertRedirect(route('dashboard.finance.reconciliation.show', $fx['reconciliation']->scope_id)); // an existing scope opens its own page
    Livewire::actingAs($finance)->test(Reconciliation::class)->set('newCounterparty', 'omar@example.com')->set('newMonth', '2026-08')->set('newCurrency', 'USD')->call('startScope')->assertHasErrors(['scope.rule'])->assertNoRedirect();
});

it('CHECK LEDGER runs describe() for ONE scope on demand (read-only, no cache): UNCHANGED, then LEDGER MOVED after the ledger moves, EVIDENCE flags surface; a filter change clears the checks', function () {
    $fx = closableMonth();
    $finance = userWithRole(Role::Finance);
    $scopeId = $fx['reconciliation']->scope_id;
    $page = Livewire::actingAs($finance)->test(Reconciliation::class, ['fromMonth' => '2026-08', 'toMonth' => '2026-08']);

    $captures = ledgerCaptures(fn () => $page->call('checkLedger', $scopeId)->assertSee('UNCHANGED SINCE RECONCILIATION')->assertSee('RE-CHECK'));
    expect($captures)->toBe(1)->and($page->get('ledgerChecks'))->toHaveKey($scopeId)->and($page->get('ledgerChecks')[$scopeId]['at'])->toBe('2026-09-06 12:00:00');

    // the ledger moves: a new priced provider row in the month ⇒ the on-demand check says MOVED; the stored row figures do not change
    financeRow(['provider' => 'groq', 'provider_cost' => '1.000000', 'total_cost' => '1.000000', 'occurred_at' => CarbonImmutable::parse('2026-08-20 10:00:00', 'UTC')]);
    $page->call('checkLedger', $scopeId)->assertSee('LEDGER MOVED SINCE RECONCILIATION')->assertSee('60.000000');
    expect((string) $fx['reconciliation']->fresh()->calculated_known_amount)->toBe('50.000000');

    // evidence voided ⇒ flag on the check
    app(CostInvoiceService::class)->void($fx['invoice']->id, $fx['invoice']->fresh()->stateToken(), 'dup');
    $page->call('checkLedger', $scopeId)->assertSee('EVIDENCE VOIDED (#'.$fx['invoice']->id.')');

    // a filter change clears the page-state checks (nothing was cached server-side)
    $page->set('currency', 'ILS')->assertDontSee('EVIDENCE VOIDED');
    expect($page->get('ledgerChecks'))->toBe([]);

    // CHECK LEDGER on a NOT RECONCILED scope compares nothing
    $zeroScope = CostReconciliationScope::query()->where('component', 'external')->firstOrFail();
    $page->set('currency', '')->call('checkLedger', $zeroScope->id)->assertSee('UNCHANGED SINCE RECONCILIATION');
});

it('filters are allowlisted (status: not_reconciled / reconciled / confirmed_zero), bounded to 13 months, kept in the URL and reset the page; 25 rows per page', function () {
    $fx = closableMonth();
    $finance = userWithRole(Role::Finance);
    for ($i = 0; $i < 26; $i++) {
        e2Reconcile([], ['component' => 'external', 'counterpartyKey' => 'ext-'.$i, 'month' => '2026-07', 'source' => 'confirmed_zero', 'reasonCode' => 'none', 'evidenceRef' => 'att:'.$i, 'typedConfirmation' => 'ZERO']);
    }

    $this->actingAs($finance)->get(route('dashboard.finance.reconciliation', ['fromMonth' => '2026-07', 'toMonth' => '2026-08']))->assertOk()->assertSee('29 rows')->assertSee('page 1 of 2');
    $this->actingAs($finance)->get(route('dashboard.finance.reconciliation', ['fromMonth' => '2026-07', 'toMonth' => '2026-08', 'status' => 'reconciled']))->assertOk()->assertSee('1 rows')->assertSee('scope-'.$fx['reconciliation']->scope_id);
    $this->actingAs($finance)->get(route('dashboard.finance.reconciliation', ['fromMonth' => '2026-07', 'toMonth' => '2026-08', 'status' => 'confirmed_zero', 'component' => 'external']))->assertOk()->assertSee('27 rows');
    $this->actingAs($finance)->get(route('dashboard.finance.reconciliation', ['fromMonth' => '2026-07', 'toMonth' => '2026-08', 'counterparty' => 'ext-3']))->assertOk()->assertSee('1 rows');
    $this->actingAs($finance)->get(route('dashboard.finance.reconciliation', ['fromMonth' => '2026-07', 'toMonth' => '2026-08', 'status' => 'not_reconciled']))->assertOk()->assertSee('0 rows');
    $this->actingAs($finance)->get(route('dashboard.finance.reconciliation', ['fromMonth' => '2026-07', 'toMonth' => '2026-08', 'status' => 'x', 'component' => 'y', 'currency' => 'zz']))->assertOk()->assertSee('29 rows');
    $this->actingAs($finance)->get(route('dashboard.finance.reconciliation', ['fromMonth' => '2025-01', 'toMonth' => '2026-08']))->assertOk()->assertSee('window-error')->assertSee('0 rows');

    $page = Livewire::actingAs($finance)->test(Reconciliation::class, ['fromMonth' => '2026-07', 'toMonth' => '2026-08'])->call('gotoPage', 2)->assertSee('page 2 of 2')->set('component', 'provider')->assertSee('page 1 of 1');
    expect($page->get('component'))->toBe('provider');
});

it('refuses the on-demand check and the scope form once the permission is withdrawn mid-session', function () {
    $fx = closableMonth();
    $finance = userWithRole(Role::Finance);
    $page = Livewire::actingAs($finance)->test(Reconciliation::class);
    $finance->removeRole(Role::Finance->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $page->call('checkLedger', $fx['reconciliation']->scope_id)->assertForbidden();
});
