<?php

declare(strict_types=1);

use App\Livewire\Dashboard\Finance\CostInvoiceDetail;
use App\Models\AuditLog;
use App\Models\CostInvoice;
use App\Models\CostInvoiceEvent;
use App\Models\CostInvoiceLine;
use App\Services\Reconciliation\CostInvoiceService;
use App\Support\Audit\AuditActions;
use App\Support\Rbac\Role;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * Phase E5.2b — /dashboard/finance/cost-invoices/{invoice}: facts, trail,
 * signed lines with Σ vs total and per-line allocatable / allocated /
 * remaining, evidence uses, superseded-by; Add Line (draft only, duplicate
 * line_no refused by the service — never a replay), Confirm / Void /
 * Supersede with the rendered token as a hidden field; stale ⇒ refreshed,
 * never re-run; one audit per transition; audit link to the CostInvoice
 * subject; RBAC re-checked on every action.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));
});

function invoicePage($user, CostInvoice $invoice)
{
    return Livewire::actingAs($user)->test(CostInvoiceDetail::class, ['invoice' => $invoice]);
}

it('shows the facts, the rendered lifecycle token, the event trail, signed lines with Σ vs total, allocatable state, source allocated and remaining per line, evidence uses and superseded-by', function () {
    $fx = closableMonth();
    $invoice = $fx['invoice']; // confirmed, one service line 60.000000 fully used by the reconciliation
    $tax = null;
    $finance = userWithRole(Role::Finance);

    $html = $this->actingAs($finance)->get(route('dashboard.finance.cost_invoices.show', $invoice->id))->assertOk();
    $line = $invoice->lines()->firstOrFail();
    $html->assertSee('data-testid="fact-token"', false)->assertSee($invoice->fresh()->stateToken())->assertSee('provider / groq')
        ->assertSee('60.000000 / 60.000000 · MATCH')->assertSee('line-'.$line->id)->assertSee('ALLOCATABLE')
        ->assertSee('evidence-')->assertSee('scope #'.$fx['reconciliation']->scope_id)
        ->assertSee('event-')->assertSee('confirmed')->assertSee('lines-frozen')->assertDontSee('open-line')
        ->assertSee('subject CostInvoice #'.$invoice->id);

    // a draft with a tax line: Σ vs total mismatch is shown, tax is NOT ALLOCATABLE, remaining shown for service only
    $draft = e2Invoice(['totalAmount' => '116.000000', 'invoiceRef' => 'D-1']);
    e2Line($draft, ['kind' => 'service', 'amount' => '100.000000']);
    $tax = e2Line($draft, ['kind' => 'tax', 'descriptionCode' => 'vat', 'amount' => '10.000000']);
    $html = $this->actingAs($finance)->get(route('dashboard.finance.cost_invoices.show', $draft->id))->assertOk();
    $html->assertSee('110.000000 / 116.000000 · MISMATCH')->assertSee('TOTAL MISMATCH')->assertSee('NOT ALLOCATABLE (never service cost)')->assertSee('open-line')->assertSee('open-confirm')->assertSee('open-void')->assertDontSee('open-supersede');

    // superseded-by is a fact of the row
    $replacement = e2ConfirmedInvoice(['service' => '60.000000'], ['invoiceRef' => 'R-1']);
    app(CostInvoiceService::class)->supersede($invoice->id, $invoice->fresh()->stateToken(), $replacement->id, 'restated');
    $this->actingAs($finance)->get(route('dashboard.finance.cost_invoices.show', $invoice->id))->assertOk()->assertSee('#'.$replacement->id)->assertSee('EVIDENCE SUPERSEDED')->assertSee('no-transition');
});

it('Add Line: draft only, the rendered token is checked, a duplicate line_no is refused by the service (rule line_no) and never replayed — exactly one row; positive credit refused; success keeps the attempt key rotating', function () {
    e2Provider();
    $finance = userWithRole(Role::Finance);
    $draft = e2Invoice(['totalAmount' => '95.000000']);
    $page = invoicePage($finance, $draft)->call('openConfirm', 'line')->assertSee('data-testid="form-line"', false);
    $key = $page->get('lineKey');
    expect($page->get('lineNo'))->toBe('1');

    $page->set('lineKind', 'credit')->set('lineCode', 'promo')->set('lineAmount', '5')->call('addLine')->assertHasErrors(['line.rule'])->assertSee('sign —');
    expect(CostInvoiceLine::count())->toBe(0)->and($page->get('lineKey'))->toBe($key);

    $page->set('lineKind', 'service')->set('lineCode', 'api_usage')->set('lineAmount', '100')->call('addLine')->assertHasNoErrors()->assertSee('أُضيف السطر');
    expect(CostInvoiceLine::count())->toBe(1)->and($page->get('lineKey'))->not->toBe($key)->and($page->get('lineNo'))->toBe('2');

    // a double submit of the SAME line_no (old snapshot) ⇒ refused by the service as line_no — no second row, no replay
    $page->call('openConfirm', 'line')->set('lineNo', '1')->set('lineKind', 'service')->set('lineCode', 'api_usage')->set('lineAmount', '100')->call('addLine')->assertHasErrors(['line.rule'])->assertSee('line_no —');
    expect(CostInvoiceLine::count())->toBe(1);

    $page->set('lineNo', '2')->set('lineKind', 'credit')->set('lineCode', 'promo')->set('lineAmount', '-5')->call('addLine')->assertHasNoErrors();
    expect(CostInvoiceLine::count())->toBe(2)->and(AuditLog::where('action', AuditActions::CostInvoiceLineAdded)->where('subject_id', $draft->id)->count())->toBe(2);

    // once confirmed the lines are frozen: the service refuses (lifecycle) and the form is not offered
    app(CostInvoiceService::class)->confirm($draft->id, $draft->fresh()->stateToken());
    $page->call('openConfirm', 'line')->set('lineNo', '3')->set('lineKind', 'service')->set('lineCode', 'x')->set('lineAmount', '1')->call('addLine')->assertHasErrors(['line.stale']); // the rendered token is stale first
    expect(CostInvoiceLine::count())->toBe(2);
});

it('Confirm / Void / Supersede use the rendered token: a state changed by another actor ⇒ STATE CHANGED, token refreshed, never re-run; reason required for void / supersede; one audit per transition; replacement candidates are confirmed same-scope invoices only', function () {
    e2Provider();
    $finance = userWithRole(Role::Finance);
    $draft = e2Invoice(['totalAmount' => '100.000000', 'invoiceRef' => 'A-1']);
    e2Line($draft, ['amount' => '100.000000']);
    $page = invoicePage($finance, $draft);
    $rendered = $page->get('invoiceToken');
    expect($rendered)->toBe($draft->fresh()->stateToken());

    // another actor confirms first ⇒ this page's confirm is stale: refused, token refreshed, no second confirmed event, no extra audit
    app(CostInvoiceService::class)->confirm($draft->id, $rendered);
    $page->call('openConfirm', 'confirm')->call('confirmInvoice')->assertHasErrors(['confirm.stale'])->assertSee('STATE CHANGED')->assertSee(CostInvoiceDetail::STALE_MESSAGE);
    expect($page->get('invoiceToken'))->toBe($draft->fresh()->stateToken())->and($page->get('invoiceToken'))->not->toBe($rendered)
        ->and(CostInvoiceEvent::query()->where('cost_invoice_id', $draft->id)->where('event_type', 'confirmed')->count())->toBe(1)
        ->and(AuditLog::where('action', AuditActions::CostInvoiceTransitioned)->where('subject_id', $draft->id)->count())->toBe(1);

    // void needs a reason (validation, before the service); supersede offers only confirmed invoices of the same component / counterparty / currency
    $page->call('openConfirm', 'void')->call('voidInvoice')->assertHasErrors(['void.validation']);
    $other = e2ConfirmedInvoice(['service' => '50.000000'], ['invoiceRef' => 'B-1']);
    $foreignCurrency = e2ConfirmedInvoice(['service' => '50.000000'], ['invoiceRef' => 'C-1', 'currency' => 'ILS']);
    $draftOnly = e2Invoice(['invoiceRef' => 'D-1']);
    $page->call('openConfirm', 'supersede')->assertSee('#'.$other->id.' · B-1')->assertDontSee('#'.$foreignCurrency->id.' · C-1')->assertDontSee('#'.$draftOnly->id.' · D-1');
    $page->set('lcReason', 'restated')->set('lcReplacementId', (string) $draftOnly->id)->call('supersedeInvoice')->assertHasErrors(['supersede.rule'])->assertSee('replacement —'); // forced id: the service is the authority
    $page->set('lcReason', 'restated')->set('lcReplacementId', (string) $other->id)->call('supersedeInvoice')->assertHasNoErrors()->assertSee('استُبدلت الفاتورة');
    expect($draft->fresh()->current_status->value)->toBe('superseded')->and($draft->fresh()->superseded_by_id)->toBe($other->id)
        ->and(AuditLog::where('action', AuditActions::CostInvoiceTransitioned)->where('subject_id', $draft->id)->count())->toBe(2);

    // void from confirmed on the replacement, with a reason, from a fresh page (rendered token current)
    $page2 = invoicePage($finance, $other)->call('openConfirm', 'void')->set('lcReason', 'duplicate')->call('voidInvoice')->assertHasNoErrors();
    expect($other->fresh()->current_status->value)->toBe('voided');
    // an illegal transition is refused by the service (rule lifecycle), not by the UI alone
    $page2->call('openConfirm', 'void')->set('lcReason', 'again')->call('voidInvoice')->assertHasErrors(['void.rule'])->assertSee('lifecycle —');
});

it('audit link: the invoice detail links to subject CostInvoice#<invoice> and the records reachable there are cost_invoice.recorded / line_added / transitioned for THIS invoice; the page itself writes no audit', function () {
    e2Provider();
    $finance = userWithRole(Role::Finance);
    $invoice = e2ConfirmedInvoice(['service' => '10.000000']);
    $line = $invoice->lines()->firstOrFail();

    $rows = AuditLog::query()->whereIn('action', [AuditActions::CostInvoiceRecorded, AuditActions::CostInvoiceLineAdded, AuditActions::CostInvoiceTransitioned])->orderBy('id')->get(['action', 'subject_type', 'subject_id']);
    $mapping = $rows->map(fn ($r) => [$r->action, class_basename($r->subject_type), $r->subject_id])->unique()->values()->all();
    expect($mapping)->toHaveCount(3)
        ->toContain([AuditActions::CostInvoiceRecorded, 'CostInvoice', $invoice->id])->toContain([AuditActions::CostInvoiceLineAdded, 'CostInvoice', $invoice->id])->toContain([AuditActions::CostInvoiceTransitioned, 'CostInvoice', $invoice->id])
        ->and(AuditLog::query()->where('subject_type', 'like', '%CostInvoiceLine%')->orWhere('subject_type', 'like', '%CostInvoiceEvent%')->count())->toBe(0);

    $detail = $this->actingAs($finance)->get(route('dashboard.finance.cost_invoices.show', $invoice->id))->assertOk()->assertSee('subject CostInvoice #'.$invoice->id);
    preg_match('/href="([^"]+)"[^>]*data-testid="audit-link"/', $detail->getContent(), $m);
    $link = html_entity_decode($m[1]);
    expect($link)->toContain('subject_type=CostInvoice')->toContain('subject_id='.$invoice->id);
    $audit = $this->get($link)->assertOk()->getContent();
    expect(str_contains($audit, 'cost_invoice.recorded'))->toBeTrue()->and(str_contains($audit, 'cost_invoice.line_added'))->toBeTrue()->and(str_contains($audit, 'cost_invoice.transitioned'))->toBeTrue()
        ->and(str_contains($audit, '&quot;id&quot;: '.$line->id))->toBeTrue(); // the line id inside the line_added changes
    expect(AuditLog::query()->where('subject_id', $invoice->id)->where('subject_type', 'like', '%CostInvoice')->count())->toBe(3)->and(AuditLog::count())->toBe($rows->count() + AuditLog::query()->whereNotIn('action', [AuditActions::CostInvoiceRecorded, AuditActions::CostInvoiceLineAdded, AuditActions::CostInvoiceTransitioned])->count()); // rendering the pages wrote nothing

    // the link is gated by audit.view (the audit page itself still requires it)
    Spatie\Permission\Models\Role::findByName(Role::Finance->value)->revokePermissionTo('audit.view');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs($finance->fresh())->get(route('dashboard.finance.cost_invoices.show', $invoice->id))->assertOk()->assertDontSee('data-testid="audit-link"', false);
    $this->get($link)->assertForbidden();
});

it('refuses every action once the permission is withdrawn mid-session, and opening a panel writes nothing', function () {
    e2Provider();
    $finance = userWithRole(Role::Finance);
    $draft = e2Invoice();
    $pages = [invoicePage($finance, $draft)->call('openConfirm', 'line')->set('lineNo', '1')->set('lineCode', 'x')->set('lineAmount', '1'), invoicePage($finance, $draft), invoicePage($finance, $draft)];
    $finance->removeRole(Role::Finance->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $pages[0]->call('addLine')->assertForbidden();
    $pages[1]->call('confirmInvoice')->assertForbidden();
    $pages[2]->call('openConfirm', 'void')->assertForbidden();
    expect(CostInvoiceLine::count())->toBe(0)->and(CostInvoiceEvent::query()->where('cost_invoice_id', $draft->id)->count())->toBe(1);
});
