<?php

declare(strict_types=1);

use App\Livewire\Dashboard\Finance\Reconciliation;
use App\Models\CostAdjustment;
use App\Models\CostInvoice;
use App\Models\CostInvoiceLine;
use App\Models\CostReconciliation;
use App\Models\User;
use App\Support\Rbac\Role;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * Phase E2 — /dashboard/finance/reconciliation: strict RBAC on the route,
 * the mount and EVERY action; the seven write operations through the
 * services; typed ZERO; no PII; no cash / contribution / gross profit figure.
 */
it('is reachable only with finance.reconcile: super_admin and finance 200, operations/support/legacy admin 403, guests redirected', function () {
    rbacSync();

    $this->get(route('dashboard.finance.reconciliation'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('dashboard.finance.reconciliation'))->assertForbidden();
    $this->actingAs(userWithRole(Role::Operations))->get(route('dashboard.finance.reconciliation'))->assertForbidden();
    $this->actingAs(userWithRole(Role::Support))->get(route('dashboard.finance.reconciliation'))->assertForbidden();
    $this->actingAs(userWithRole(Role::Finance))->get(route('dashboard.finance.reconciliation'))->assertOk();
    $this->actingAs(userWithRole(Role::SuperAdmin))->get(route('dashboard.finance.reconciliation'))->assertOk();
});

it('shows the nav link only to accounts holding finance.reconcile', function () {
    $this->actingAs(userWithRole(Role::Finance))->get(route('dashboard'))->assertOk()->assertSee(route('dashboard.finance.reconciliation'));
    $this->actingAs(userWithRole(Role::Operations))->get(route('dashboard'))->assertOk()->assertDontSee(route('dashboard.finance.reconciliation'));
    $this->actingAs(userWithRole(Role::Support))->get(route('dashboard'))->assertOk()->assertDontSee(route('dashboard.finance.reconciliation'));
});

it('refuses every action once the permission is withdrawn mid-session (server-side re-authorization), including Confirm Zero', function () {
    e2Provider();
    $finance = userWithRole(Role::Finance);
    $component = Livewire::actingAs($finance)->test(Reconciliation::class)->assertOk()
        ->set('invCounterparty', 'groq')->set('invCurrency', 'USD')->set('invTotal', '10')
        ->set('recCounterparty', 'groq')->set('recMonth', '2026-08')->set('recCurrency', 'USD')->set('recSource', 'confirmed_zero')->set('recReason', 'none')->set('recEvidence', 'att:1')->set('recTyped', 'ZERO');

    $finance->removeRole(Role::Finance->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $component->call('recordInvoice')->assertForbidden();
    expect(CostInvoice::count())->toBe(0);

    $component = Livewire::actingAs(userWithRole(Role::Finance))->test(Reconciliation::class)->assertOk()
        ->set('recCounterparty', 'groq')->set('recMonth', '2026-08')->set('recCurrency', 'USD')->set('recSource', 'confirmed_zero')->set('recReason', 'none')->set('recEvidence', 'att:1')->set('recTyped', 'ZERO');
    auth()->user()->removeRole(Role::Finance->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $component->call('reconcile')->assertForbidden();
    expect(CostReconciliation::count())->toBe(0);
});

it('drives the whole flow from the page: draft → lines → confirm → reconcile from evidence → adjust; then confirm zero needs the typed word', function () {
    e2Provider();
    financeRow(['provider_cost' => '80.000000', 'total_cost' => '80.000000', 'occurred_at' => CarbonImmutable::parse('2026-08-10', 'UTC')]);
    $finance = userWithRole(Role::Finance);

    $page = Livewire::actingAs($finance)->test(Reconciliation::class)
        ->set('invComponent', 'provider')->set('invCounterparty', 'groq')->set('invRef', 'GROQ-2026-08')->set('invCurrency', 'usd')->set('invTotal', '116.000000')
        ->set('invIssuedAt', '2026-09-02')->set('invPeriodStart', '2026-08-01')->set('invPeriodEnd', '2026-09-01')
        ->call('recordInvoice')->assertHasNoErrors()->assertSee('سُجِّلت الفاتورة');

    $invoice = CostInvoice::query()->firstOrFail();
    expect($invoice->recorded_by_ref)->toBe('user:'.$finance->id)->and($invoice->currency)->toBe('USD');

    $page->set('lineInvoiceId', (string) $invoice->id)->set('lineNo', '1')->set('lineKind', 'service')->set('lineCode', 'api_usage')->set('lineAmount', '100')
        ->call('addLine')->assertHasNoErrors()
        ->set('lineNo', '2')->set('lineKind', 'tax')->set('lineCode', 'vat')->set('lineAmount', '16')
        ->call('addLine')->assertHasNoErrors()
        ->set('lineNo', '3')->set('lineKind', 'credit')->set('lineCode', 'promo')->set('lineAmount', '5')
        ->call('addLine')->assertHasErrors(['line']); // a positive credit is refused

    $page->set('lcInvoiceId', (string) $invoice->id)->set('lcToken', 'i:0')->call('confirmInvoice')->assertHasErrors(['lifecycle']) // stale token
        ->set('lcToken', $invoice->fresh()->stateToken())->call('confirmInvoice')->assertHasNoErrors()->assertSee('أُكِّدت الفاتورة');
    expect($invoice->fresh()->isConfirmed())->toBeTrue();

    $serviceLine = CostInvoiceLine::query()->where('kind', 'service')->firstOrFail();
    $taxLine = CostInvoiceLine::query()->where('kind', 'tax')->firstOrFail();

    $page->set('recComponent', 'provider')->set('recCounterparty', 'groq')->set('recMonth', '2026-08')->set('recCurrency', 'USD')->set('recSource', 'invoice')
        ->set('recAllocations.0.line', (string) $taxLine->id)->set('recAllocations.0.amount', '16')
        ->call('reconcile')->assertHasErrors(['reconciliation']) // tax is never service cost
        ->set('recAllocations.0.line', (string) $serviceLine->id)->set('recAllocations.0.amount', '100')
        ->call('reconcile')->assertHasNoErrors()->assertSee('سُجِّلت التسوية');

    $rec = CostReconciliation::query()->firstOrFail();
    expect((string) $rec->reconciled_amount)->toBe('100.000000')->and((string) $rec->calculated_known_amount)->toBe('80.000000')->and($rec->actor_ref)->toBe('user:'.$finance->id);

    $page->set('adjReconciliationId', (string) $rec->id)->set('adjAmount', '-2.5')->set('adjReason', 'credit_note')->set('adjEvidence', 'cn:9')
        ->call('adjust')->assertHasNoErrors()->assertSee('المبلغ الأساسي لم يتغيّر');
    expect(CostAdjustment::count())->toBe(1)->and((string) $rec->fresh()->reconciled_amount)->toBe('100.000000');

    // Confirm zero for the external component: typed ZERO is mandatory.
    $page->set('recComponent', 'external')->set('recCounterparty', 'none-declared')->set('recMonth', '2026-08')->set('recCurrency', 'USD')->set('recExpected', '')->set('recSource', 'confirmed_zero')
        ->set('recAllocations', [['line' => '', 'amount' => ''], ['line' => '', 'amount' => ''], ['line' => '', 'amount' => '']])
        ->set('recReason', 'no_external')->set('recEvidence', 'att:2026-08')->set('recTyped', 'zero')
        ->call('reconcile')->assertHasErrors(['reconciliation'])
        ->set('recTyped', 'ZERO')->call('reconcile')->assertHasNoErrors()->assertSee('CONFIRMED ZERO');

    $html = $this->actingAs($finance)->get(route('dashboard.finance.reconciliation', ['fromMonth' => '2026-08', 'toMonth' => '2026-08']))->assertOk();
    $html->assertSee('CONFIRMED ZERO')->assertSee('UNKNOWN (NO PRODUCER)')->assertSee('Variance vs Known Calculated Cost')->assertSee('Adjusted Reconciled Cost')
        ->assertSee('97.500000')->assertSee('20.000000') // adjusted cost; original variance 100 − 80
        ->assertSee('Reconciled Cash Contribution / Period Close: <strong>NOT AVAILABLE — E4</strong>', false)
        ->assertSee('Gross Profit: <strong>NOT AVAILABLE</strong>', false)
        ->assertSee('provider / groq / 2026-08 / USD') // the counterparty is its stable key, nothing else
        ->assertDontSee('Gross Margin:')->assertDontSee('Revenue:');
});

it('surfaces domain refusals as form errors, refuses PII-shaped counterparties and free text, and reports a bad month window', function () {
    e2Provider();
    Livewire::actingAs(userWithRole(Role::Finance))->test(Reconciliation::class)
        ->set('invComponent', 'communication')->set('invCounterparty', 'omar@example.com')->set('invCurrency', 'USD')->set('invTotal', '10')
        ->call('recordInvoice')->assertHasErrors(['invoice'])
        ->set('invCounterparty', 'Meta Platforms Inc')->call('recordInvoice')->assertHasErrors(['invoice'])
        ->set('invCounterparty', 'meta-whatsapp')->set('invTotal', 'abc')->call('recordInvoice')->assertHasErrors(['invoice'])
        ->set('lineInvoiceId', '999')->set('lineNo', '1')->set('lineCode', 'x')->set('lineAmount', '1')->call('addLine')->assertHasErrors(['line'])
        ->set('adjReconciliationId', '999')->set('adjAmount', '1')->set('adjReason', 'x')->set('adjEvidence', 'y')->call('adjust')->assertHasErrors(['adjustment'])
        ->set('fromMonth', '2026-13')->assertSee('YYYY-MM');

    expect(CostInvoice::count())->toBe(0)->and(CostInvoiceLine::count())->toBe(0)->and(CostAdjustment::count())->toBe(0);
});
