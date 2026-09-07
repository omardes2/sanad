<?php

declare(strict_types=1);

use App\Livewire\Dashboard\Finance\PeriodClose;
use App\Models\CostReconciliation;
use App\Models\FinancePeriodClose;
use App\Models\User;
use App\Services\Reconciliation\CostReconciliationService;
use App\Support\Rbac\Role;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * Phase E4 → E5.2c — /dashboard/finance/close: finance.view opens the page
 * read-only (frozen history; preflight ONLY on demand), close/reopen are
 * super_admin only (403 for finance even with every other finance
 * permission), typed confirmations, no revenue / gross profit / margin
 * wording for the contribution.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));
});

it('opens for finance.view (finance, super_admin) and refuses operations/support/legacy admin and guests', function () {
    rbacSync();

    $this->get(route('dashboard.finance.close'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('dashboard.finance.close'))->assertForbidden();
    $this->actingAs(userWithRole(Role::Operations))->get(route('dashboard.finance.close'))->assertForbidden();
    $this->actingAs(userWithRole(Role::Support))->get(route('dashboard.finance.close'))->assertForbidden();
    $this->actingAs(userWithRole(Role::Finance))->get(route('dashboard.finance.close'))->assertOk()->assertSee('عرض فقط')->assertDontSee('data-testid="form-close"', false);
    $this->actingAs(userWithRole(Role::SuperAdmin))->get(route('dashboard.finance.close'))->assertOk()->assertSee('data-testid="form-close"', false);
});

it('lets finance read the preflight but refuses close and reopen actions for finance, even mid-session', function () {
    closableMonth();
    $finance = userWithRole(Role::Finance);

    $page = Livewire::actingAs($finance)->test(PeriodClose::class)->set('month', '2026-08')->assertOk()
        ->assertSee('data-testid="preflight-idle"', false)->assertDontSee('READY TO CLOSE') // nothing is evaluated on render
        ->call('runPreflight')
        ->assertSee('READY TO CLOSE')->assertSee('131.000000')->assertSee('CONFIRMED_ZERO')
        ->set('closeTyped', 'CLOSE 2026-08');
    $page->call('close')->assertForbidden();
    expect(FinancePeriodClose::count())->toBe(0);

    // A super_admin who loses the role mid-session is refused too.
    $admin = userWithRole(Role::SuperAdmin);
    $page = Livewire::actingAs($admin)->test(PeriodClose::class)->set('month', '2026-08')->set('closeTyped', 'CLOSE 2026-08');
    $admin->removeRole(Role::SuperAdmin->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $page->call('close')->assertForbidden();
    expect(FinancePeriodClose::count())->toBe(0);
});

it('drives close → drift → reopen → second close from the page for super_admin with typed confirmations', function () {
    closableMonth();
    $admin = userWithRole(Role::SuperAdmin);

    $page = Livewire::actingAs($admin)->test(PeriodClose::class)->set('month', '2026-08')
        ->set('closeTyped', 'close 2026-08')->call('close')->assertHasErrors(['close.rule'])
        ->set('closeTyped', 'CLOSE 2026-08')->call('close')->assertHasNoErrors()->assertSee('أُقفل الشهر 2026-08')->assertSee('Reconciled Cash Contribution: 131.000000 USD');
    $close = FinancePeriodClose::query()->firstOrFail();
    expect($close->actor_ref)->toBe('user:'.$admin->id);

    $page->set('closeTyped', 'CLOSE 2026-08')->call('close')->assertHasErrors(['close.rule']); // ALREADY_CLOSED

    app(CostReconciliationService::class)->adjust(CostReconciliation::query()->where('component', 'provider')->firstOrFail()->id, '-1.000000', 'credit', 'cn:2', e2Key());
    // Drift is on demand only: the rendered page offers the check, it never runs one.
    $this->actingAs($admin)->get(route('dashboard.finance.close', ['month' => '2026-08']))->assertOk()
        ->assertSee('CHECK CURRENT DRIFT')->assertDontSee('DRIFT SINCE CLOSE')->assertSee('CLOSED');
    Livewire::actingAs($admin)->test(PeriodClose::class)->set('month', '2026-08')->call('checkDrift', $close->id)->assertSee('DRIFT SINCE CLOSE');

    $page->set('expectedCloseId', (string) $close->id)->set('reopenCloseId', (string) $close->id)->set('reopenTyped', 'REOPEN 2026-08')->set('reopenReason', 'restatement')->set('reopenEvidence', 'memo:1')
        ->call('reopen')->assertHasNoErrors()->assertSee('أُعيد فتح الشهر')
        ->set('closeTyped', 'CLOSE 2026-08')->call('close')->assertHasNoErrors()->assertSee('المراجعة 2');

    expect(FinancePeriodClose::count())->toBe(3)->and((string) FinancePeriodClose::query()->orderByDesc('id')->first()->reconciled_cash_contribution)->toBe('132.000000')
        ->and((string) $close->fresh()->reconciled_cash_contribution)->toBe('131.000000');

    $html = $this->actingAs($admin)->get(route('dashboard.finance.close', ['month' => '2026-08']))->assertOk();
    $html->assertSee('REOPENED')->assertSee('Reconciled Cash Contribution')->assertSee('Gross Profit / Margin / Revenue Recognition: <strong>NOT AVAILABLE</strong>', false)
        ->assertDontSee('Gross Profit:')->assertDontSee('Margin:')->assertDontSee('Revenue:')->assertDontSee('Accounting Profit');
});

it('reports blocked months with their conditions and NOT AVAILABLE figures', function () {
    closableMonth();
    e1Payment(billingSubscriber(), ['amount' => '1.00', 'currency' => 'USD', 'receivedAt' => CarbonImmutable::parse('2026-08-25', 'UTC')]);

    Livewire::actingAs(userWithRole(Role::SuperAdmin))->test(PeriodClose::class)->set('month', '2026-08')
        ->call('runPreflight')->assertSee('BLOCKED')->assertSee('FEES_INCOMPLETE')->assertSee('NOT AVAILABLE')
        ->set('closeTyped', 'CLOSE 2026-08')->call('close')->assertHasErrors(['close.rule'])->assertSee('BLOCKED — ')
        ->set('month', '2026-13')->call('runPreflight')->assertHasErrors(['preflight.validation'])->assertSee('YYYY-MM');
    expect(FinancePeriodClose::count())->toBe(0);
});

it('the preview is informational only: a stale READY preview cannot close a month that is now blocked, and a stale BLOCKED preview cannot stop a month that is now clean (E5.2c)', function () {
    $fx = closableMonth();
    $admin = userWithRole(Role::SuperAdmin);

    // 1) READY when previewed, blocked by the time the close runs ⇒ the service refuses; nothing is written.
    $page = Livewire::actingAs($admin)->test(PeriodClose::class)->set('month', '2026-08')->call('runPreflight')->assertSee('READY TO CLOSE');

    $late = e1Payment($fx['subscriber'], ['amount' => '73.00', 'currency' => 'ILS', 'receivedAt' => CarbonImmutable::parse('2026-08-20 09:00:00', 'UTC'), 'gatewayFeeAmount' => '0.73', 'feeCurrency' => 'ILS']);

    $page->assertSee('READY TO CLOSE') // the preview is not re-evaluated on render — it still shows the old answer
        ->set('closeTyped', 'CLOSE 2026-08')->call('close')->assertHasErrors(['close.rule'])->assertSee('FX_INCOMPLETE_CASH');
    expect(FinancePeriodClose::count())->toBe(0);

    // 2) BLOCKED when previewed, clean by the time the close runs ⇒ the service closes it.
    $page->call('runPreflight')->assertSee('BLOCKED')->assertSee('FX_INCOMPLETE_CASH');
    $rate = fxRate(['rate' => '3.65', 'rateDate' => '2026-08-20']);
    fxConvert('customer_payment', $late->id, 'USD', $rate->id);

    $page->assertSee('BLOCKED') // still the stale preview…
        ->set('closeTyped', 'CLOSE 2026-08')->call('close')->assertHasNoErrors()->assertSee('أُقفل الشهر 2026-08'); // …but the service decides on the live month
    expect(FinancePeriodClose::count())->toBe(1);
});

it('reopen from the page carries its attempt key as the service idempotency key: the same key with the same facts writes one row, a different key on the moved pointer is stale (E5.2c)', function () {
    closableMonth();
    $admin = userWithRole(Role::SuperAdmin);
    $close = closeMonth('2026-08', null, 'k1');

    $page = Livewire::actingAs($admin)->test(PeriodClose::class)->set('month', '2026-08')
        ->assertSet('expectedCloseId', (string) $close->id)
        ->call('selectReopen', $close->id)->assertSet('reopenCloseId', (string) $close->id)
        ->set('reopenTyped', 'REOPEN 2026-08')->set('reopenReason', 'restatement')->set('reopenEvidence', 'memo:1');

    $key = $page->get('reopenKey');
    $page->call('reopen')->assertHasNoErrors()->assertSee('أُعيد فتح الشهر');
    $reopen = FinancePeriodClose::query()->where('idempotency_key', $key)->firstOrFail();

    // The same attempt replayed with the same facts: the recorded row comes back, nothing new is written.
    $page->set('reopenKey', $key)->set('expectedCloseId', (string) $close->id)->set('reopenCloseId', (string) $close->id)
        ->set('reopenTyped', 'REOPEN 2026-08')->set('reopenReason', 'restatement')->set('reopenEvidence', 'memo:1')
        ->call('reopen')->assertHasNoErrors()->assertSee('مسجَّلة مسبقًا');

    expect(FinancePeriodClose::query()->where('status', 'reopened')->count())->toBe(1)
        ->and(FinancePeriodClose::count())->toBe(2);

    // A fresh key against the pointer the page still shows (already moved by the reopen) is stale — never a second reopen.
    $page->set('reopenKey', 'ui:'.str()->random(12))->set('expectedCloseId', (string) $close->id)->set('reopenCloseId', (string) $close->id)
        ->set('reopenTyped', 'REOPEN 2026-08')->set('reopenReason', 'restatement')->set('reopenEvidence', 'memo:1')
        ->call('reopen')->assertHasErrors(['reopen.stale'])->assertSet('expectedCloseId', (string) $reopen->id);

    expect(FinancePeriodClose::query()->where('status', 'reopened')->count())->toBe(1);
});
