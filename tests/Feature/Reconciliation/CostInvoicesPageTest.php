<?php

declare(strict_types=1);

use App\Livewire\Dashboard\Finance\CostInvoices;
use App\Models\CostInvoice;
use App\Models\User;
use App\Support\Rbac\Role;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * Phase E5.2b — /dashboard/finance/cost-invoices: strict RBAC on the route,
 * the mount and every action; Record Invoice through CostInvoiceService with
 * one attempt key per attempt (the service's own idempotency key); allowlisted,
 * bounded (≤ 13 months), URL-kept filters; 25-row pagination in id-desc order;
 * no PII; no revenue / gross profit figure; UTC.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));
});

it('is reachable only with finance.reconcile: super_admin and finance 200, operations/support/legacy admin/no-role 403, guests redirected; the nav links show only to holders', function () {
    rbacSync();

    $this->get(route('dashboard.finance.cost_invoices'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('dashboard.finance.cost_invoices'))->assertForbidden();
    $this->actingAs(User::factory()->create(['is_admin' => false]))->get(route('dashboard.finance.cost_invoices'))->assertForbidden();
    $this->actingAs(userWithRole(Role::Operations))->get(route('dashboard.finance.cost_invoices'))->assertForbidden();
    $this->actingAs(userWithRole(Role::Support))->get(route('dashboard.finance.cost_invoices'))->assertForbidden();
    $this->actingAs(userWithRole(Role::Finance))->get(route('dashboard.finance.cost_invoices'))->assertOk();
    $this->actingAs(userWithRole(Role::SuperAdmin))->get(route('dashboard.finance.cost_invoices'))->assertOk();

    $this->actingAs(userWithRole(Role::Finance))->get(route('dashboard'))->assertOk()->assertSee(route('dashboard.finance.cost_invoices'))->assertSee(route('dashboard.finance.reconciliation'));
    $this->actingAs(userWithRole(Role::Operations))->get(route('dashboard'))->assertOk()->assertDontSee(route('dashboard.finance.cost_invoices'))->assertDontSee(route('dashboard.finance.reconciliation'));
});

it('refuses the action for an account whose permission was withdrawn after the page was opened (server-side re-authorization)', function () {
    e2Provider();
    $finance = userWithRole(Role::Finance);
    $page = Livewire::actingAs($finance)->test(CostInvoices::class)->assertOk()->set('invCounterparty', 'groq')->set('invCurrency', 'USD')->set('invTotal', '10');

    $finance->removeRole(Role::Finance->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $page->call('recordInvoice')->assertForbidden();
    expect(CostInvoice::count())->toBe(0);
});

it('records a draft from the form with one attempt key: a refused attempt keeps the key, the same key + same facts is the same invoice, the same key + different facts is an IDEMPOTENCY CONFLICT, success rotates the key; PII-shaped counterparties refused', function () {
    e2Provider();
    $finance = userWithRole(Role::Finance);
    $page = Livewire::actingAs($finance)->test(CostInvoices::class)->assertOk();
    $key = $page->get('invKey');
    expect($key)->toStartWith('ui:');

    // refused by the service (unknown provider key) ⇒ REFUSED BY SERVICE with the rule name, key kept
    $page->set('invComponent', 'provider')->set('invCounterparty', 'unknown-provider')->set('invCurrency', 'USD')->set('invTotal', '116.000000')
        ->set('invIssuedAt', '2026-09-02')->set('invPeriodStart', '2026-08-01')->set('invPeriodEnd', '2026-09-01')
        ->call('recordInvoice')->assertHasErrors(['invoice.rule'])->assertSee('counterparty_key —');
    expect(CostInvoice::count())->toBe(0)->and($page->get('invKey'))->toBe($key);

    // PII-shaped / free-text counterparty ⇒ refused
    $page->set('invComponent', 'communication')->set('invCounterparty', 'omar@example.com')->call('recordInvoice')->assertHasErrors(['invoice.rule']);
    $page->set('invCounterparty', 'Meta Platforms Inc')->call('recordInvoice')->assertHasErrors(['invoice.rule']);
    $page->set('invCounterparty', 'meta-whatsapp')->set('invTotal', 'abc')->call('recordInvoice')->assertHasErrors(['invoice.rule']);
    $page->set('invIssuedAt', 'not-a-date')->set('invTotal', '10')->call('recordInvoice')->assertHasErrors(['invoice.validation']);
    expect(CostInvoice::count())->toBe(0)->and($page->get('invKey'))->toBe($key);

    $page->set('invComponent', 'provider')->set('invCounterparty', 'groq')->set('invRef', 'GROQ-2026-08')->set('invCurrency', 'usd')->set('invTotal', '116.000000')->set('invIssuedAt', '2026-09-02')
        ->call('recordInvoice')->assertHasNoErrors()->assertSee('سُجِّلت الفاتورة');
    $invoice = CostInvoice::query()->sole();
    expect($invoice->idempotency_key)->toBe($key)->and($invoice->currency)->toBe('USD')->and($invoice->recorded_by_ref)->toBe('user:'.$finance->id)
        ->and($page->get('invKey'))->not->toBe($key);

    // same key + same facts (double submit with the old snapshot) ⇒ the same invoice, no second row
    $page->set('invKey', $key)->set('invCounterparty', 'groq')->set('invRef', 'GROQ-2026-08')->set('invCurrency', 'USD')->set('invTotal', '116.000000')
        ->call('recordInvoice')->assertHasNoErrors()->assertSee('مسجَّلة مسبقًا');
    expect(CostInvoice::count())->toBe(1);

    // same key + different facts ⇒ IDEMPOTENCY CONFLICT, nothing written, key kept
    $page->set('invKey', $key)->set('invCounterparty', 'groq')->set('invRef', 'GROQ-2026-08')->set('invCurrency', 'USD')->set('invTotal', '117.000000')->call('recordInvoice')->assertHasErrors(['invoice.conflict'])->assertSee('IDEMPOTENCY CONFLICT');
    expect(CostInvoice::count())->toBe(1)->and($page->get('invKey'))->toBe($key);
});

it('filters are allowlisted, bounded to 13 months, kept in the URL and reset the page; an invalid window lists nothing; 25 rows per page in id-desc order', function () {
    e2Provider();
    $finance = userWithRole(Role::Finance);
    for ($i = 0; $i < 27; $i++) {
        e2Invoice(['invoiceRef' => 'REF-'.$i, 'currency' => $i % 2 ? 'USD' : 'ILS']);
    }
    $external = e2Invoice(['component' => 'external', 'counterpartyKey' => 'ext-1', 'periodStart' => CarbonImmutable::parse('2026-07-01', 'UTC'), 'periodEnd' => CarbonImmutable::parse('2026-08-01', 'UTC')]);
    $last = CostInvoice::query()->max('id');

    $this->actingAs($finance)->get(route('dashboard.finance.cost_invoices', ['fromMonth' => '2026-08', 'toMonth' => '2026-08']))->assertOk()
        ->assertSee('27 rows')->assertSee('page 1 of 2')->assertSee('invoice-'.($last - 1))->assertDontSee('invoice-'.$external->id);
    $this->actingAs($finance)->get(route('dashboard.finance.cost_invoices', ['fromMonth' => '2026-07', 'toMonth' => '2026-08', 'component' => 'external']))->assertOk()->assertSee('1 rows')->assertSee('invoice-'.$external->id);
    $this->actingAs($finance)->get(route('dashboard.finance.cost_invoices', ['fromMonth' => '2026-08', 'toMonth' => '2026-08', 'ref' => 'REF-3']))->assertOk()->assertSee('1 rows');
    $this->actingAs($finance)->get(route('dashboard.finance.cost_invoices', ['fromMonth' => '2026-08', 'toMonth' => '2026-08', 'currency' => 'ils']))->assertOk()->assertSee('14 rows');
    $this->actingAs($finance)->get(route('dashboard.finance.cost_invoices', ['fromMonth' => '2026-08', 'toMonth' => '2026-08', 'status' => 'confirmed']))->assertOk()->assertSee('0 rows');
    $this->actingAs($finance)->get(route('dashboard.finance.cost_invoices', ['fromMonth' => '2026-08', 'toMonth' => '2026-08', 'status' => 'DROP TABLE', 'component' => 'x', 'currency' => 'usdollar', 'counterparty' => 'a b']))->assertOk()->assertSee('27 rows'); // outside the allowlist ⇒ ignored
    $this->actingAs($finance)->get(route('dashboard.finance.cost_invoices', ['fromMonth' => '2025-01', 'toMonth' => '2026-08']))->assertOk()->assertSee('data-testid="window-error"', false)->assertSee('0 rows');
    $this->actingAs($finance)->get(route('dashboard.finance.cost_invoices', ['fromMonth' => '2026-13', 'toMonth' => '2026-08']))->assertOk()->assertSee('data-testid="window-error"', false)->assertSee('0 rows');

    $page = Livewire::actingAs($finance)->test(CostInvoices::class, ['fromMonth' => '2026-08', 'toMonth' => '2026-08'])->call('gotoPage', 2)->assertSee('page 2 of 2')
        ->set('currency', 'ILS')->assertSee('page 1 of 1')->assertSee('14 rows');
    expect($page->get('currency'))->toBe('ILS');
    expect(CostInvoices::window('2026-01', '2027-01')[1]->format('Y-m-d'))->toBe('2027-02-01')
        ->and(fn () => CostInvoices::window('2026-01', '2027-02'))->toThrow(InvalidArgumentException::class, '13');
});

it('renders ids, keys and UTC dates only — no names, no e-mails, no revenue / gross profit wording', function () {
    $fx = closableMonth();
    $html = $this->actingAs(userWithRole(Role::Finance))->get(route('dashboard.finance.cost_invoices', ['fromMonth' => '2026-08', 'toMonth' => '2026-08']))->assertOk();
    $html->assertSee('provider / groq')->assertSee('2026-08-01 → 2026-09-01')->assertSee('timezone <span dir="ltr">UTC</span>', false)
        ->assertDontSee('@')->assertDontSee('Revenue:')->assertDontSee('Gross Margin')->assertDontSee('Accounting Profit');
});
