<?php

declare(strict_types=1);

use App\Livewire\Dashboard\Finance\CloseDetail;
use App\Livewire\Dashboard\Finance\PeriodClose;
use App\Models\FinancePeriodClose;
use App\Models\FinancePeriodCloseScope;
use App\Models\User;
use App\Services\Close\PeriodCloseService;
use App\Services\Fx\ReportingCurrencyService;
use App\Services\Reconciliation\CostReconciliationService;
use App\Support\Rbac\Role;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * Phase E5.1 — /dashboard/finance/close/{close}: frozen data only (close row
 * + input rows), never the live preflight; CHECK CURRENT DRIFT is an
 * explicit on-demand action whose answer sits next to unchanged frozen
 * values; revision chain, period UTC, hash, input rows grouped by type with
 * FX facts; read-only audit links; finance.view only; no PII. The close
 * history page reads frozen rows and offers drift on demand per revision.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));
});

/** One <tr data-testid="…"> … </tr> of a rendered page. */
function tableRow(string $html, string $testid): string
{
    $start = strpos($html, 'data-testid="'.$testid.'"');
    expect($start)->not->toBeFalse($testid.' not found');
    $end = strpos($html, '</tr>', $start);

    return substr($html, $start, $end - $start);
}

it('renders a historical close from its frozen row and inputs — live changes, FX rate corrections and a reporting-currency change never alter what is shown', function () {
    $fx = closableMonth();
    $close = closeMonth('2026-08', null, 'k1');
    $inputHash = $close->input_hash;

    // Live world moves on: new adjustment (live contribution 132), a corrected FX quote, a new reporting currency.
    app(CostReconciliationService::class)->adjust($fx['reconciliation']->id, '-1.000000', 'credit', 'cn:2');
    $corrected = fxRate(['rate' => '3.70', 'rateDate' => '2026-08-10', 'expectedCurrentRateId' => $fx['rate']->id, 'reasonCode' => 'correction', 'evidenceRef' => 'boi:rev2']);
    app(ReportingCurrencyService::class)->change('ILS', 'ILS');

    $page = $this->actingAs(userWithRole(Role::Finance))->get(route('dashboard.finance.close.show', $close->id))->assertOk();
    $html = $page->getContent();

    $page->assertSee('FROZEN CLOSE REVISION 1')->assertSee('CURRENT close of its scope')
        ->assertSee('data-testid="frozen-reconciled_cash_contribution"', false)->assertSee('131.000000')->assertDontSee('132.000000')
        ->assertSee('200.000000')->assertSee('186.000000')->assertSee('55.000000')
        ->assertSee($inputHash)
        ->assertSee('2026-08-01 00:00:00 → 2026-09-01 00:00:00')
        ->assertSee('FROZEN · CONFIRMED_ZERO')
        ->assertSee('Gross Profit / Margin / Revenue Recognition: <strong>NOT AVAILABLE</strong>', false)
        ->assertDontSee('Gross Profit:')->assertDontSee('Margin:')->assertDontSee('Revenue:')
        ->assertDontSee($fx['subscriber']->email)->assertDontSee($fx['subscriber']->name);

    // Reporting currency on the frozen page is the close's (USD), not the new live one.
    $identity = substr($html, strpos($html, 'data-testid="identity"'), 3000);
    expect($identity)->toContain('>USD<')->not->toContain('>ILS<');

    // The ILS gateway fee row keeps the ORIGINAL rate facts: fx_rate_id of the rate used at close time, snapshot 3.65, inverse — not the corrected quote.
    $feeRow = tableRow($html, 'input-gateway_fee-'.$fx['ils']->id);
    $cells = array_map('trim', array_map('strip_tags', preg_split('/<\/td>/', $feeRow)));
    expect($feeRow)->toContain('3.65')->toContain('CONVERTED')->toContain('1.000000')->toContain('inverse')->toContain('2026-08-10')->toContain('3.650000000000')->not->toContain('3.700000000000')
        ->and($cells[7])->toBe((string) $fx['rate']->id) // fx rate id cell = the rate frozen at close time
        ->and($cells[7])->not->toBe((string) $corrected->id);

    // Rows per type with amounts, currencies and reporting amounts; the reconciliation row carries its refs.
    expect($html)->toContain('data-testid="inputs-payment"')->toContain('data-testid="inputs-refund"')->toContain('data-testid="inputs-gateway_fee"')->toContain('data-testid="inputs-reconciliation"')->toContain('data-testid="inputs-adjustment"')
        ->and(tableRow($html, 'input-reconciliation-'.$fx['reconciliation']->id))->toContain('component:provider')->toContain('counterparty:groq')->toContain('60.000000')
        ->and(tableRow($html, 'input-adjustment-'.$fx['adjustment']->id))->toContain('-5.000000')->toContain('reconciliation:'.$fx['reconciliation']->id);

    // Same row, same figures, after everything moved.
    expect((string) $close->fresh()->reconciled_cash_contribution)->toBe('131.000000')->and($close->fresh()->input_hash)->toBe($inputHash);
});

it('CHECK CURRENT DRIFT is on demand only: nothing on render, an explicit answer after the click, frozen values unchanged either way', function () {
    $fx = closableMonth();
    $close = closeMonth('2026-08', null, 'k1');

    $page = Livewire::actingAs(userWithRole(Role::Finance))->test(CloseDetail::class, ['close' => $close])
        ->assertSee('CHECK CURRENT DRIFT')->assertDontSee('data-testid="drift-result"', false)
        ->call('checkDrift')->assertSee('NO DRIFT')->assertSee('131.000000');

    app(CostReconciliationService::class)->adjust($fx['reconciliation']->id, '-1.000000', 'credit', 'cn:2');
    $page->call('checkDrift')->assertSee('DRIFT SINCE CLOSE')->assertSee('frozen values unchanged')->assertSee('131.000000')->assertDontSee('132.000000');
    expect((string) $close->fresh()->reconciled_cash_contribution)->toBe('131.000000');
});

it('shows the revision chain, the reopen record, read-only audit entries with a link into the audit page, and the CSV link only with finance.export', function () {
    closableMonth();
    $v1 = closeMonth('2026-08', null, 'k1');
    $reopen = app(PeriodCloseService::class)->reopen($v1->id, $v1->id, 'restatement', 'memo:1', 'REOPEN 2026-08');
    $v2 = closeMonth('2026-08', $reopen->id, 'k2');
    $scope = FinancePeriodCloseScope::query()->firstOrFail();
    $finance = userWithRole(Role::Finance);

    $this->actingAs($finance)->get(route('dashboard.finance.close.show', $v2->id))->assertOk()
        ->assertSee('FROZEN CLOSE REVISION 2')->assertSee(route('dashboard.finance.close.show', $v1->id))->assertSee('CURRENT close of its scope')
        ->assertSee('finance.period_closed')->assertSee('finance.period_reopened')->assertSee('data-testid="audit-link"', false)
        ->assertSee(e(route('dashboard.audit', ['subject_type' => 'FinancePeriodCloseScope', 'subject_id' => $scope->id])), false)
        ->assertSee(route('dashboard.finance.close.export', $v2->id), false);

    $this->actingAs($finance)->get(route('dashboard.finance.close.show', $v1->id))->assertOk()->assertSee('historical record (not the current pointer)')->assertSee('131.000000');
    $this->actingAs($finance)->get(route('dashboard.finance.close.show', $reopen->id))->assertOk()->assertSee('REOPEN RECORD')->assertSee('restatement')->assertSee('memo:1')->assertDontSee('data-testid="section-figures"', false);

    $viewer = userWithRole(Role::Finance);
    Spatie\Permission\Models\Role::findByName(Role::Finance->value)->revokePermissionTo('finance.export');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs($viewer->fresh())->get(route('dashboard.finance.close.show', $v2->id))->assertOk()->assertDontSee('data-testid="close-export-link"', false);
});

it('is reachable only with finance.view: finance and super_admin 200, operations/support/legacy admin 403, guests redirected, unknown close 404', function () {
    rbacSync();
    closableMonth();
    $close = closeMonth('2026-08', null, 'k1');
    $url = route('dashboard.finance.close.show', $close->id);

    $this->get($url)->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create(['is_admin' => true]))->get($url)->assertForbidden();
    $this->actingAs(userWithRole(Role::Operations))->get($url)->assertForbidden();
    $this->actingAs(userWithRole(Role::Support))->get($url)->assertForbidden();
    $this->actingAs(userWithRole(Role::Finance))->get($url)->assertOk();
    $this->actingAs(userWithRole(Role::SuperAdmin))->get($url)->assertOk();
    $this->actingAs(userWithRole(Role::SuperAdmin))->get(route('dashboard.finance.close.show', 999))->assertNotFound();
});

it('close history page: rows come from the frozen rows, the current close drift is derived from the already-evaluated live hash, older revisions get CHECK CURRENT DRIFT on demand', function () {
    $fx = closableMonth();
    $v1 = closeMonth('2026-08', null, 'k1');
    $reopen = app(PeriodCloseService::class)->reopen($v1->id, $v1->id, 'restatement', 'memo:1', 'REOPEN 2026-08');
    app(CostReconciliationService::class)->adjust($fx['reconciliation']->id, '-1.000000', 'credit', 'cn:2');
    $v2 = closeMonth('2026-08', $reopen->id, 'k2');

    $page = Livewire::actingAs(userWithRole(Role::Finance))->test(PeriodClose::class)->set('month', '2026-08')
        ->assertSee('FROZEN CLOSE REVISION 1')->assertSee('FROZEN CLOSE REVISION 2')->assertSee('reopen record (rev 1)')
        ->assertSee('data-testid="drift-'.$v2->id.'"', false)->assertSee('NO DRIFT') // current close: free comparison, no extra evaluation
        ->assertSee('data-testid="check-drift-'.$v1->id.'"', false)->assertDontSee('data-testid="drift-'.$v1->id.'"', false) // older revision: on demand
        ->assertSee(route('dashboard.finance.close.show', $v1->id))->assertSee(route('dashboard.finance.close.export', $v2->id));

    $page->call('checkDrift', $v1->id)->assertSee('data-testid="drift-'.$v1->id.'"', false)->assertSee('DRIFT SINCE CLOSE'); // v1 was closed before the adjustment
    expect((string) $v1->fresh()->reconciled_cash_contribution)->toBe('131.000000')->and((string) $v2->fresh()->reconciled_cash_contribution)->toBe('132.000000')
        ->and(FinancePeriodClose::count())->toBe(3);
});
