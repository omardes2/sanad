<?php

declare(strict_types=1);

use App\Livewire\Dashboard\Finance;
use App\Models\CostReconciliation;
use App\Models\FinancePeriodClose;
use App\Models\User;
use App\Services\Close\PeriodCloseService;
use App\Services\Reconciliation\CostReconciliationService;
use App\Support\Rbac\Role;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * Phase E5.1 — the rebuilt finance overview: three vocabularies in separate
 * bands, CASH live per currency with FEES UNKNOWN never zero, reporting
 * totals only when complete, RECONCILED as a per-month series with exactly
 * one basis each (FROZEN CLOSE REVISION n from the close row, or LIVE /
 * CURRENT from preflight), no cross-month / cross-currency / cross-revision
 * total, no gross profit figure, no PII, strict RBAC.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));
});

function overview(User $user, string $from = '2026-08-01', string $to = '2026-09-06')
{
    return test()->actingAs($user)->get(route('dashboard.finance', ['from' => $from, 'to' => $to]))->assertOk();
}

/** The HTML of one month row of the Reconciled band. */
function monthRow(string $html, string $month): string
{
    $start = strpos($html, 'data-testid="month-'.$month.'"');
    expect($start)->not->toBeFalse();
    $end = strpos($html, '</tr>', $start);

    return substr($html, $start, $end - $start);
}

it('renders the five bands with the three vocabularies visibly separate and the basis labels, without any gross profit / revenue figure', function () {
    closableMonth();
    $html = overview(userWithRole(Role::Finance))
        ->assertSee('Band: CALCULATED')->assertSee('Band: CASH · basis LIVE / CURRENT')->assertSee('Band: RECONCILED')
        ->assertSee('data-testid="section-current"', false)->assertSee('data-testid="section-window"', false)->assertSee('data-testid="section-cash"', false)->assertSee('data-testid="section-reconciled"', false)->assertSee('data-testid="section-history"', false)
        ->assertSee('Known Provider Cost')->assertSee('Gross Cash Collected')->assertSee('Reconciled Service Cost')->assertSee('Reconciled Cash Contribution')
        ->assertSee('FEES UNKNOWN / NOT CONVERTED / NOT RECONCILED / NOT AVAILABLE')
        ->assertSee('NOT AVAILABLE — no Revenue Recognition policy')
        ->assertSee('No total row by design')
        ->assertDontSee('Gross Profit:')->assertDontSee('Margin:')->assertDontSee('Revenue:')->assertDontSee('Accounting Profit')
        ->getContent();

    // The Gross Margin card is a status card only — no amount inside it.
    $card = substr($html, strpos($html, 'data-testid="gross-margin"'), 1200);
    expect(preg_match('/\d+\.\d{6}/', $card))->toBe(0);
});

it('CASH band: live per native currency, FEES UNKNOWN never 0, reporting totals INCOMPLETE / NOT AVAILABLE while any line is NOT CONVERTED and complete once converted', function () {
    $fx = closableMonth(); // USD 100 (fee 3) + ILS 365 (fee 3.65, converted) + refund 10 USD
    $eur = e1Payment($fx['subscriber'], ['amount' => '50.00', 'currency' => 'EUR', 'receivedAt' => CarbonImmutable::parse('2026-08-21', 'UTC')]); // fee NULL, not converted

    $html = overview(userWithRole(Role::Finance))->getContent();
    $usd = substr($html, strpos($html, 'data-testid="cash-USD"'), 1600);
    $ils = substr($html, strpos($html, 'data-testid="cash-ILS"'), 1600);
    $eurCard = substr($html, strpos($html, 'data-testid="cash-EUR"'), 1600);
    $reporting = substr($html, strpos($html, 'data-testid="cash-reporting"'), 2500);

    expect($usd)->toContain('Gross Cash Collected')->toContain('100.00')->toContain('Refunds')->toContain('10.00')->toContain('90.00')->toContain('>3.00<')->toContain('known')->toContain('87.00') // 100 − 10 − 3
        ->and($ils)->toContain('365.00')->toContain('3.65')->toContain('361.35')
        ->and($eurCard)->toContain('FEES UNKNOWN (1 of 1)')->toContain('no partial fee total')->toContain('NOT AVAILABLE')
        ->and(preg_match('/Gateway Fees<\/p><p[^>]*>0\.00/', $eurCard))->toBe(0) // an unknown fee is never rendered as a zero
        ->and(substr($html, strpos($html, 'data-testid="reporting-gross"'), 400))->toContain('INCOMPLETE / NOT AVAILABLE')->toContain('NOT CONVERTED 1') // the EUR payment blocks every reporting total
        ->and(substr($html, strpos($html, 'data-testid="reporting-net"'), 400))->toContain('INCOMPLETE / NOT AVAILABLE');

    // Convert the EUR payment ⇒ every line NATIVE or CONVERTED ⇒ totals appear: gross 100 + 100 + 50 = 250, refunds 10, net 240 (USD).
    $rate = fxRate(['baseCurrency' => 'EUR', 'quoteCurrency' => 'USD', 'rate' => '1.00', 'rateDate' => '2026-08-21']);
    fxConvert('customer_payment', $eur->id, 'USD', $rate->id);
    $html = overview(userWithRole(Role::Finance))->getContent();
    $gross = substr($html, strpos($html, 'data-testid="reporting-gross"'), 400);
    $net = substr($html, strpos($html, 'data-testid="reporting-net"'), 400);
    expect($gross)->toContain('250.00')->not->toContain('INCOMPLETE')->and($net)->toContain('240.00')->not->toContain('INCOMPLETE');
});

it('RECONCILED band: a closed month is FROZEN CLOSE REVISION n from the close row and never re-evaluated; an open month is LIVE / CURRENT with its blockers; the two are never mixed', function () {
    closableMonth();
    $close = closeMonth('2026-08', null, 'k1');
    // Live data moves after the close: a new adjustment changes the live contribution to 132 — the frozen row must still say 131.
    app(CostReconciliationService::class)->adjust(CostReconciliation::query()->where('component', 'provider')->firstOrFail()->id, '-1.000000', 'credit', 'cn:2');

    $html = overview(userWithRole(Role::Finance))->getContent();
    $aug = monthRow($html, '2026-08');
    $sep = monthRow($html, '2026-09');

    expect($aug)->toContain('data-basis="frozen"')->toContain('FROZEN CLOSE REVISION 1')->toContain('131.000000')->toContain('55.000000')->toContain('186.000000')->not->toContain('132.000000')
        ->toContain(route('dashboard.finance.close.show', $close->id))->toContain('FROZEN · CONFIRMED_ZERO')
        ->and($sep)->toContain('data-basis="live"')->toContain('LIVE / CURRENT')->toContain('never closed')->toContain('BLOCKING · PERIOD_NOT_ENDED')->toContain('NOT AVAILABLE')
        ->and(substr_count($html, 'data-basis="'))->toBe(2);

    // After a reopen the month is LIVE / CURRENT again (state reopened) and shows the live figure — the old close row is untouched.
    $this->actingAs(userWithRole(Role::SuperAdmin)); // reopen is super_admin only
    app(PeriodCloseService::class)->reopen($close->id, $close->id, 'restatement', 'memo:1', 'REOPEN 2026-08');
    $aug = monthRow(overview(userWithRole(Role::Finance))->getContent(), '2026-08');
    expect($aug)->toContain('data-basis="live"')->toContain('LIVE / CURRENT')->toContain('132.000000')->not->toContain('FROZEN CLOSE REVISION')
        ->and((string) FinancePeriodClose::query()->findOrFail($close->id)->reconciled_cash_contribution)->toBe('131.000000');
});

it('never aggregates closes: a window spanning months lists a series with no total, and only the current reporting currency and the current revision enter the band', function () {
    closableMonth();
    $first = closeMonth('2026-08', null, 'k1');
    app(PeriodCloseService::class)->reopen($first->id, $first->id, 'restatement', 'memo:1', 'REOPEN 2026-08');
    app(CostReconciliationService::class)->adjust(CostReconciliation::query()->where('component', 'provider')->firstOrFail()->id, '-1.000000', 'credit', 'cn:2');
    $second = closeMonth('2026-08', FinancePeriodClose::query()->orderByDesc('id')->first()->id, 'k2'); // revision 2 = 132

    $html = overview(userWithRole(Role::Finance), '2026-07-15', '2026-09-06')->getContent();
    $table = substr($html, strpos($html, 'data-testid="months-table"'), strpos($html, 'No total row by design') - strpos($html, 'data-testid="months-table"'));

    expect(substr_count($table, 'data-testid="month-2026-'))->toBe(3) // 2026-07, 2026-08, 2026-09 — a series
        ->and(monthRow($html, '2026-08'))->toContain('FROZEN CLOSE REVISION 2')->toContain('132.000000')->not->toContain('131.000000')->toContain('close #'.$second->id)
        ->and(monthRow($html, '2026-07'))->toContain('LIVE / CURRENT')
        ->and($table)->not->toContain('263.000000')->not->toContain('Total') // never 131 + 132, never a total row
        ->and(preg_match('/<tfoot/i', $table))->toBe(0);
});

it('is reachable only with finance.view and shows the four CSV links only with finance.export; no PII anywhere', function () {
    rbacSync();
    $fx = closableMonth();
    $params = ['from' => '2026-08-01', 'to' => '2026-09-06'];

    $this->get(route('dashboard.finance', $params))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('dashboard.finance', $params))->assertForbidden();
    $this->actingAs(userWithRole(Role::Operations))->get(route('dashboard.finance', $params))->assertForbidden();
    $this->actingAs(userWithRole(Role::Support))->get(route('dashboard.finance', $params))->assertForbidden();

    $finance = overview(userWithRole(Role::Finance))
        ->assertSee(e(route('dashboard.finance.cash.export', ['from' => '2026-08-01', 'to' => '2026-09-06'])), false)
        ->assertSee(e(route('dashboard.finance.cost.export', ['from' => '2026-08', 'to' => '2026-09'])), false)
        ->assertSee(e(route('dashboard.finance.fx.export', ['from' => '2026-08-01', 'to' => '2026-09-06'])), false)
        ->assertDontSee($fx['subscriber']->email)->assertDontSee($fx['subscriber']->name);

    $viewer = userWithRole(Role::Finance);
    Spatie\Permission\Models\Role::findByName(Role::Finance->value)->revokePermissionTo('finance.export');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    overview($viewer->fresh())->assertDontSee('data-testid="export-links"', false)->assertDontSee('/finance/cash/export');
});

it('rejects an invalid window with a message and renders no cash or reconciled band', function () {
    Livewire::actingAs(userWithRole(Role::Finance))
        ->test(Finance::class, ['from' => '2026-01-01', 'to' => '2026-12-31'])
        ->assertSee('النطاق الأقصى')
        ->assertDontSee('Band: CASH')->assertDontSee('Band: RECONCILED');
});
