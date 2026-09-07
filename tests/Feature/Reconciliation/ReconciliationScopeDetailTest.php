<?php

declare(strict_types=1);

use App\Livewire\Dashboard\Finance\ReconciliationScopeDetail;
use App\Models\AuditLog;
use App\Models\CostAdjustment;
use App\Models\CostInvoiceAllocation;
use App\Models\CostReconciliation;
use App\Models\CostReconciliationScope;
use App\Services\Reconciliation\CostInvoiceService;
use App\Services\Reconciliation\CostReconciliationService;
use App\Support\Audit\AuditActions;
use App\Support\Rbac\Role;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * Phase E5.2b — /dashboard/finance/reconciliation/{scope}: identity, pointer
 * and token, current reconciliation, frozen revision history (never
 * recomputed), evidence allocations with frozen FX facts, adjustments, the
 * LIVE flags of this one scope through the shared banners under the
 * preflight's codes; Reconcile from eligible evidence with explicit shares
 * and explicit FX quotes, manual evidenced, CONFIRMED ZERO (typed), Adjust
 * with the durable key; expected-pointer stale ⇒ refreshed, never re-run;
 * audit link to the CostReconciliationScope subject; RBAC on every action.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));
});

function scopePage($user, CostReconciliationScope $scope)
{
    return Livewire::actingAs($user)->test(ReconciliationScopeDetail::class, ['scope' => $scope]);
}

it('shows identity, pointer / token, current figures, the frozen revision history with supersedes_id, snapshot, evidence FX facts and adjustments; live flags stay separate from frozen facts (LEDGER_MOVED / EVIDENCE_STALE banners)', function () {
    $fx = closableMonth();
    $finance = userWithRole(Role::Finance);
    $rec = $fx['reconciliation'];
    $scope = CostReconciliationScope::query()->findOrFail($rec->scope_id);
    $line = $fx['invoice']->lines()->firstOrFail();
    $allocation = CostInvoiceAllocation::query()->where('cost_reconciliation_id', $rec->id)->firstOrFail();

    $html = $this->actingAs($finance)->get(route('dashboard.finance.reconciliation.show', $scope->id))->assertOk();
    $html->assertSee('provider / groq / 2026-08 / USD')->assertSee('#'.$rec->id.' · <span data-testid="fact-token">r:'.$rec->id.'</span>', false)->assertSee('RECONCILED')
        ->assertSee('60.000000 · -5.000000 · 55.000000') // base · adjustments · adjusted
        ->assertSee('revision-'.$rec->id)->assertSee('CURRENT · source invoice')->assertSee('known 50.000000 · priced 1 · unpriced 0 · mismatch 0')->assertSee('captured 2026-09-06')
        ->assertSee('10.000000 · adjusted 5.000000') // frozen variance 60 − 50, adjusted 55 − 50
        ->assertSee('allocation-'.$allocation->id)->assertSee('invoice #'.$fx['invoice']->id)->assertSee('NATIVE')->assertSee('adjustment-'.$fx['adjustment']->id)->assertSee('credit_note')
        ->assertSee('UNCHANGED SINCE RECONCILIATION')->assertDontSee('data-banner="blocking"', false)
        ->assertSee('subject CostReconciliationScope #'.$scope->id);

    // a second revision supersedes the first: history keeps both, the old snapshot is not recomputed
    $rec2 = e2Reconcile([[$line->id, '0.000000']], ['expectedCurrentReconciliationId' => $rec->id, 'source' => 'manual_evidenced', 'allocations' => [], 'reconciledAmount' => '70.000000', 'reasonCode' => 'stmt', 'evidenceRef' => 'stmt:9']);
    financeRow(['provider' => 'groq', 'provider_cost' => '1.000000', 'total_cost' => '1.000000', 'occurred_at' => CarbonImmutable::parse('2026-08-20 10:00:00', 'UTC')]); // the ledger moves AFTER rec2
    app(CostInvoiceService::class)->void($fx['invoice']->id, $fx['invoice']->fresh()->stateToken(), 'dup');

    $html = $this->actingAs($finance)->get(route('dashboard.finance.reconciliation.show', $scope->id))->assertOk();
    $html->assertSee('revision-'.$rec2->id)->assertSee('supersedes #'.$rec->id)->assertSee('· superseded')->assertSee('revision-'.$rec->id)
        ->assertSee('known 50.000000 · priced 1') // both frozen snapshots still say 50 — the moved ledger is a LIVE flag, not a rewrite
        ->assertSee('LEDGER MOVED SINCE RECONCILIATION')->assertSee('BLOCKING · LEDGER_MOVED · reconciliation:'.$rec2->id)
        ->assertDontSee('EVIDENCE_STALE'); // rec2 (manual) drew on no invoice: the voided invoice flags rec1's history only, not the CURRENT revision
    expect((string) CostReconciliation::query()->findOrFail($rec->id)->calculated_known_amount)->toBe('50.000000');
});

it('reconciles from evidence through the new-scope route: eligible lines, explicit shares, cap refused verbatim, success creates the scope row and redirects to its detail', function () {
    e2Provider();
    financeRow(['provider' => 'groq', 'provider_cost' => '80.000000', 'total_cost' => '80.000000', 'occurred_at' => CarbonImmutable::parse('2026-08-10', 'UTC')]);
    $finance = userWithRole(Role::Finance);
    $invoice = e2ConfirmedInvoice(['service' => '100.000000', 'tax' => '16.000000', 'credit' => '-10.000000']);
    $service = $invoice->lines()->where('kind', 'service')->firstOrFail();
    $credit = $invoice->lines()->where('kind', 'credit')->firstOrFail();
    $tax = $invoice->lines()->where('kind', 'tax')->firstOrFail();
    $draft = e2Invoice(['invoiceRef' => 'DRAFT']);
    e2Line($draft, ['amount' => '100.000000']);
    $other = e2ConfirmedInvoice(['service' => '5.000000'], ['component' => 'communication', 'counterpartyKey' => 'meta-whatsapp']);

    $page = Livewire::withQueryParams(['component' => 'provider', 'counterparty' => 'groq', 'month' => '2026-08', 'currency' => 'USD'])->actingAs($finance)->test(ReconciliationScopeDetail::class)
        ->assertSee('NOT RECONCILED')->assertSee('no-revisions')->call('openConfirm', 'evidence')->assertSee('data-testid="form-evidence"', false)
        ->assertSee('line #'.$service->id)->assertSee('remaining 100.000000')->assertSee('line #'.$credit->id)->assertSee('negative (credit)')->assertSee('remaining -10.000000')
        ->assertDontSee('line #'.$tax->id)->assertDontSee('DRAFT')->assertDontSee('line #'.$other->lines()->firstOrFail()->id);
    $key = $page->get('reconcileKey');
    expect($page->get('expectedId'))->toBe('')->and($page->get('scopeToken'))->toBe('r:0');

    // tax forced into the form ⇒ the service refuses (line_kind); over the line ⇒ allocation_limit; nothing written, key kept
    $page->set('evidenceRows.0.line', (string) $tax->id)->set('evidenceRows.0.amount', '16')->call('reconcileFromEvidence')->assertHasErrors(['reconcile.rule'])->assertSee('line_kind —');
    $page->set('evidenceRows.0.line', (string) $service->id)->set('evidenceRows.0.amount', '100.000001')->call('reconcileFromEvidence')->assertHasErrors(['reconcile.rule'])->assertSee('allocation_limit —');
    $page->set('evidenceRows.0.amount', '-1')->call('reconcileFromEvidence')->assertHasErrors(['reconcile.rule'])->assertSee('sign —');
    expect(CostReconciliation::count())->toBe(0)->and(CostReconciliationScope::count())->toBe(0) // no scope row until the first reconciliation
        ->and($page->get('reconcileKey'))->toBe($key);

    // explicit shares: 60 of the service line and −10 of the credit ⇒ reconciled 50; the scope row is created and the page redirects to it
    $page->set('evidenceRows.0.amount', '60')->call('addEvidenceRow')->set('evidenceRows.1.line', (string) $credit->id)->set('evidenceRows.1.amount', '-10')->set('evReason', 'monthly')
        ->call('reconcileFromEvidence')->assertHasNoErrors();
    $scope = CostReconciliationScope::query()->where('counterparty_key', 'groq')->sole();
    $page->assertRedirect(route('dashboard.finance.reconciliation.show', $scope->id));
    $rec = CostReconciliation::query()->where('scope_id', $scope->id)->sole();
    expect((string) $rec->reconciled_amount)->toBe('50.000000')->and((string) $rec->calculated_known_amount)->toBe('80.000000')->and($rec->actor_ref)->toBe('user:'.$finance->id)
        ->and(CostInvoiceAllocation::query()->where('cost_reconciliation_id', $rec->id)->count())->toBe(2)
        ->and(AuditLog::where('action', AuditActions::CostReconciled)->where('subject_id', $scope->id)->count())->toBe(1);

    // the detail page now shows remaining 40 for the service line and the pointer
    scopePage($finance, $scope)->assertSee('#'.$rec->id.' · <span data-testid="fact-token">r:'.$rec->id.'</span>', false)->call('openConfirm', 'evidence')->assertSee('allocated 60.000000 · remaining 40.000000')->assertSee('allocated -10.000000 · remaining 0.000000');
});

it('cross-currency evidence needs an explicit fx_rate_id from the quotes dated on the invoice issued_at: none ⇒ FX_REQUIRED with a link to the FX page; no default, no latest; a superseded rate revision ⇒ STATE CHANGED; the chosen rate is frozen on the allocation', function () {
    e2Provider();
    $finance = userWithRole(Role::Finance);
    $ils = e2ConfirmedInvoice(['service' => '365.000000'], ['currency' => 'ILS', 'issuedAt' => CarbonImmutable::parse('2026-09-02', 'UTC')]);
    $line = $ils->lines()->firstOrFail();

    $page = Livewire::withQueryParams(['component' => 'provider', 'counterparty' => 'groq', 'month' => '2026-08', 'currency' => 'USD'])->actingAs($finance)->test(ReconciliationScopeDetail::class)
        ->call('openConfirm', 'evidence')->set('evidenceRows.0.line', (string) $line->id)->set('evidenceRows.0.amount', '365')
        ->assertSee('data-testid="fx-required-0"', false)->assertSee('FX_REQUIRED')->assertSee(route('dashboard.finance.fx'))->assertDontSee('rate #');
    $page->call('reconcileFromEvidence')->assertHasErrors(['reconcile.rule'])->assertSee('FX_REQUIRED —'); // the service refuses too, nothing implicit
    expect(CostReconciliation::count())->toBe(0);

    // a quote on another date is NOT offered (no nearest / latest); the issued_at quote is, but must be chosen explicitly
    $wrongDate = fxRate(['baseCurrency' => 'USD', 'quoteCurrency' => 'ILS', 'rateDate' => '2026-09-01', 'rate' => '3.600000000000']);
    $page->call('openConfirm', 'evidence')->assertDontSee('rate #'.$wrongDate->id);
    $rate = fxRate(['baseCurrency' => 'USD', 'quoteCurrency' => 'ILS', 'rateDate' => '2026-09-02', 'rate' => '3.650000000000']);
    $page->call('openConfirm', 'evidence')->assertSee('rate #'.$rate->id.' · USD/ILS 3.650000000000 · 2026-09-02 (current revision)')->assertSee('— choose explicitly —');
    expect($page->get('evidenceRows.0.fx_rate_id'))->toBe('');
    $page->call('reconcileFromEvidence')->assertHasErrors(['reconcile.rule'])->assertSee('FX_REQUIRED —'); // still nothing chosen ⇒ refused

    // forcing the wrong-date rate id ⇒ FX_RATE_MISSING (policy date mismatch), refused
    $page->set('evidenceRows.0.fx_rate_id', (string) $wrongDate->id)->call('reconcileFromEvidence')->assertHasErrors(['reconcile.rule'])->assertSee('FX_RATE_MISSING —');

    // the chosen revision gets superseded before submit ⇒ stale (STATE CHANGED), never re-run with the new revision
    $page->set('evidenceRows.0.fx_rate_id', (string) $rate->id);
    $newer = fxRate(['baseCurrency' => 'USD', 'quoteCurrency' => 'ILS', 'rateDate' => '2026-09-02', 'rate' => '3.700000000000', 'expectedCurrentRateId' => $rate->id]);
    $page->call('reconcileFromEvidence')->assertHasErrors(['reconcile.stale'])->assertSee('STATE CHANGED');
    expect(CostReconciliation::count())->toBe(0);

    $page->call('openConfirm', 'evidence')->assertSee('rate #'.$newer->id)->assertDontSee('rate #'.$rate->id.' ·')->set('evidenceRows.0.fx_rate_id', (string) $newer->id)->call('reconcileFromEvidence')->assertHasNoErrors();
    $allocation = CostInvoiceAllocation::query()->sole();
    expect((string) $allocation->source_amount)->toBe('365.000000')->and($allocation->source_currency)->toBe('ILS')->and((string) $allocation->amount)->toBe('98.648649')->and($allocation->currency)->toBe('USD')
        ->and($allocation->fx_rate_id)->toBe($newer->id)->and((string) $allocation->fx_rate_snapshot)->toBe('3.700000000000')->and($allocation->fx_direction->value)->toBe('inverse')->and($allocation->fx_rate_date->format('Y-m-d'))->toBe('2026-09-02');
    $scope = CostReconciliationScope::query()->sole();
    $this->actingAs($finance)->get(route('dashboard.finance.reconciliation.show', $scope->id))->assertOk()->assertSee('CONVERTED · rate #'.$newer->id.' · 3.700000000000 · inverse · 2026-09-02');
});

it('CONFIRMED ZERO is a separate typed attestation (ZERO literally, reason, evidence, no amount) shown as CONFIRMED ZERO — never 0; manual evidenced needs amount + reason + evidence', function () {
    $finance = userWithRole(Role::Finance);
    $page = Livewire::withQueryParams(['component' => 'external', 'counterparty' => 'none-declared', 'month' => '2026-08', 'currency' => 'USD'])->actingAs($finance)->test(ReconciliationScopeDetail::class)
        ->call('openConfirm', 'zero')->assertSee('data-testid="form-zero"', false)->assertDontSee('data-testid="zero-amount"', false);
    $key = $page->get('zeroKey');
    $page->set('zeroTyped', 'zero')->set('zeroReason', 'no_external')->set('zeroEvidence', 'att:2026-08')->call('confirmZero')->assertHasErrors(['zero.rule'])->assertSee('typed_confirmation —');
    $page->set('zeroTyped', 'ZERO')->set('zeroReason', '')->call('confirmZero')->assertHasErrors(['zero.rule'])->assertSee('evidence —');
    expect(CostReconciliation::count())->toBe(0)->and($page->get('zeroKey'))->toBe($key);

    $page->set('zeroReason', 'no_external')->call('confirmZero')->assertHasNoErrors();
    $scope = CostReconciliationScope::query()->sole();
    $page->assertRedirect(route('dashboard.finance.reconciliation.show', $scope->id));
    $rec = CostReconciliation::query()->sole();
    expect($rec->source->value)->toBe('confirmed_zero')->and((string) $rec->reconciled_amount)->toBe('0.000000');
    $html = $this->actingAs($finance)->get(route('dashboard.finance.reconciliation.show', $scope->id))->assertOk();
    $html->assertSee('CONFIRMED ZERO')->assertSee('INFO · CONFIRMED_ZERO')->assertSee('UNKNOWN (NO PRODUCER)')->assertDontSee('rev-base-'.$rec->id.'">0.000000', false);
    expect(substr_count($html->getContent(), 'CONFIRMED ZERO'))->toBeGreaterThanOrEqual(3);

    // manual evidenced on a new communication scope
    $page = Livewire::withQueryParams(['component' => 'communication', 'counterparty' => 'meta-whatsapp', 'month' => '2026-08', 'currency' => 'USD'])->actingAs($finance)->test(ReconciliationScopeDetail::class)
        ->call('openConfirm', 'manual')->set('manAmount', '12.500000')->call('reconcileManual')->assertHasErrors(['manual.rule'])->assertSee('evidence —')
        ->set('manReason', 'stmt')->set('manEvidence', 'stmt:8')->call('reconcileManual')->assertHasNoErrors();
    expect(CostReconciliation::query()->where('component', 'communication')->sole()->source->value)->toBe('manual_evidenced');
});

it('expected-pointer contract: a reconciliation by another actor after render ⇒ STATE CHANGED, the pointer is refreshed and NO second reconciliation is written; the user then decides again explicitly; a stale UI pre-check never reaches the service', function () {
    $fx = closableMonth();
    $finance = userWithRole(Role::Finance);
    $rec = $fx['reconciliation'];
    $scope = CostReconciliationScope::query()->findOrFail($rec->scope_id);
    $line = $fx['invoice']->lines()->firstOrFail();
    $page = scopePage($finance, $scope);
    expect($page->get('expectedId'))->toBe((string) $rec->id);

    // another actor supersedes rec with a manual revision
    $rec2 = e2Reconcile([], ['expectedCurrentReconciliationId' => $rec->id, 'source' => 'manual_evidenced', 'reconciledAmount' => '61.000000', 'reasonCode' => 'stmt', 'evidenceRef' => 'stmt:1']);
    $audits = fn () => AuditLog::where('action', AuditActions::CostReconciled)->where('subject_id', $scope->id)->count();

    $page->call('openConfirm', 'manual')->set('manAmount', '62')->set('manReason', 'x')->set('manEvidence', 'y')->call('reconcileManual')->assertHasErrors(['manual.stale'])->assertSee('STATE CHANGED')->assertSee(ReconciliationScopeDetail::STALE_MESSAGE);
    expect($page->get('expectedId'))->toBe((string) $rec2->id)->and($page->get('scopeToken'))->toBe('r:'.$rec2->id)
        ->and(CostReconciliation::query()->where('scope_id', $scope->id)->count())->toBe(2)->and($scope->fresh()->version)->toBe(2)->and($audits())->toBe(2)
        ->and($page->get('manAmount'))->toBe('62'); // the form is kept for the user's review; nothing was re-run

    // the user decides again, explicitly, from the refreshed pointer
    $page->call('reconcileManual')->assertHasNoErrors();
    expect(CostReconciliation::query()->where('scope_id', $scope->id)->count())->toBe(3)->and($scope->fresh()->version)->toBe(3)->and($audits())->toBe(3)
        ->and($page->get('expectedId'))->toBe((string) $scope->fresh()->current_reconciliation_id);

    // a forced old pointer in the hidden field is refused by the service as stale (no second reconciliation)
    $page->set('expectedId', (string) $rec->id)->set('scopeToken', 'r:'.$rec->id)->call('openConfirm', 'manual')->set('manAmount', '1')->set('manReason', 'x')->set('manEvidence', 'y')->call('reconcileManual')->assertHasErrors(['manual.stale']);
    expect(CostReconciliation::query()->where('scope_id', $scope->id)->count())->toBe(3);
});

it('Adjust: on the current reconciliation only, signed amount ≠ 0, reason and evidence; the attempt key is the durable service key — a same-key replay returns the same adjustment (no row, no audit), a different payload conflicts; Base never changes; Adjusted shown separately', function () {
    $fx = closableMonth();
    $finance = userWithRole(Role::Finance);
    $rec = $fx['reconciliation'];
    $scope = CostReconciliationScope::query()->findOrFail($rec->scope_id);
    $page = scopePage($finance, $scope)->call('openConfirm', 'adjust')->assertSee('data-testid="form-adjustment"', false)->assertSee('Base 60.000000 never changes');
    $key = $page->get('adjustKey');
    $audits = fn () => AuditLog::where('action', AuditActions::CostAdjusted)->where('subject_id', $scope->id)->count();

    $page->set('adjAmount', '0')->set('adjReason', 'x')->set('adjEvidence', 'y')->call('adjust')->assertHasErrors(['adjustment.rule'])->assertSee('amount —');
    $page->set('adjAmount', '-2.5')->set('adjReason', '')->call('adjust')->assertHasErrors(['adjustment.rule'])->assertSee('reason_code —');
    expect(CostAdjustment::count())->toBe(1)->and($page->get('adjustKey'))->toBe($key);

    $page->set('adjReason', 'credit_note')->set('adjEvidence', 'cn:2')->call('adjust')->assertHasNoErrors()->assertSee('أُضيف التعديل')->assertSee('60.000000 · -7.500000 · 52.500000');
    $adjustment = CostAdjustment::query()->where('idempotency_key', $key)->sole();
    expect((string) $adjustment->amount)->toBe('-2.500000')->and($page->get('adjustKey'))->not->toBe($key)->and($audits())->toBe(2)->and((string) $rec->fresh()->reconciled_amount)->toBe('60.000000');

    // replay with the old key + same facts ⇒ the same adjustment, no new row / audit
    $page->set('adjustKey', $key)->call('openConfirm', 'adjust')->set('adjAmount', '-2.5')->set('adjReason', 'credit_note')->set('adjEvidence', 'cn:2')->call('adjust')->assertHasNoErrors()->assertSee('مسجَّل مسبقًا بنفس المفتاح')->assertSee('#'.$adjustment->id);
    expect(CostAdjustment::count())->toBe(2)->and($audits())->toBe(2);

    // same key + different amount ⇒ IDEMPOTENCY CONFLICT, key kept, nothing written
    $page->set('adjustKey', $key)->call('openConfirm', 'adjust')->set('adjAmount', '-3')->set('adjReason', 'credit_note')->set('adjEvidence', 'cn:2')->call('adjust')->assertHasErrors(['adjustment.conflict'])->assertSee('IDEMPOTENCY CONFLICT');
    expect(CostAdjustment::count())->toBe(2)->and($audits())->toBe(2)->and($page->get('adjustKey'))->toBe($key);

    // superseded reconciliation ⇒ the page's rendered token is stale first; after refresh the adjust targets the new current only
    $rec2 = e2Reconcile([], ['expectedCurrentReconciliationId' => $rec->id, 'source' => 'manual_evidenced', 'reconciledAmount' => '61.000000', 'reasonCode' => 'stmt', 'evidenceRef' => 'stmt:1']);
    $page->set('adjustKey', 'ui:'.str()->uuid())->call('openConfirm', 'adjust')->set('adjAmount', '-1')->set('adjReason', 'x')->set('adjEvidence', 'y')->call('adjust')->assertHasErrors(['adjustment.stale']);
    $page->call('adjust')->assertHasNoErrors(); // explicit second decision on the refreshed pointer
    expect(CostAdjustment::query()->orderByDesc('id')->firstOrFail()->cost_reconciliation_id)->toBe($rec2->id)->and(CostAdjustment::count())->toBe(3);
});

it('historical adjustment replay is safe: an adjustment with key X on v1, then the scope moves to v2 ⇒ the service replay with X returns the v1 adjustment only (no pointer move, nothing on v2, no audit); from the page (current = v2) the same key is an IDEMPOTENCY CONFLICT and never shown as a new adjustment on the current reconciliation', function () {
    e2Provider();
    $finance = userWithRole(Role::Finance);
    $this->actingAs($finance);
    $invoice = e2ConfirmedInvoice(['service' => '100.000000']);
    $line = $invoice->lines()->firstOrFail();
    $v1 = e2Reconcile([[$line->id, '60.000000']]);
    $scope = CostReconciliationScope::query()->findOrFail($v1->scope_id);
    $service = app(CostReconciliationService::class);
    $key = 'ui:'.str()->uuid();
    $historical = $service->adjust($v1->id, '-5.000000', 'credit_note', 'cn:1', $key);
    $page = scopePage($finance, $scope); // rendered while v1 is current

    $v2 = e2Reconcile([[$line->id, '40.000000']], ['expectedCurrentReconciliationId' => $v1->id]);
    $audits = fn () => AuditLog::where('action', AuditActions::CostAdjusted)->where('subject_id', $scope->id)->count();
    expect($scope->fresh()->current_reconciliation_id)->toBe($v2->id)->and($audits())->toBe(1);

    // service-level replay with X on the historical reconciliation ⇒ the SAME v1 adjustment: no row, no audit, no pointer move
    $replay = $service->adjust($v1->id, '-5.000000', 'credit_note', 'cn:1', $key);
    expect($replay->id)->toBe($historical->id)->and($replay->wasRecentlyCreated)->toBeFalse()->and($replay->cost_reconciliation_id)->toBe($v1->id)
        ->and(CostAdjustment::count())->toBe(1)->and(CostAdjustment::query()->where('cost_reconciliation_id', $v2->id)->count())->toBe(0)
        ->and($scope->fresh()->current_reconciliation_id)->toBe($v2->id)->and($scope->fresh()->version)->toBe(2)->and($audits())->toBe(1);

    // page path: the rendered token is stale first (pointer moved) — refreshed, nothing re-run
    $page->set('adjustKey', $key)->call('openConfirm', 'adjust')->set('adjAmount', '-5')->set('adjReason', 'credit_note')->set('adjEvidence', 'cn:1')->call('adjust')->assertHasErrors(['adjustment.stale']);
    expect($page->get('expectedId'))->toBe((string) $v2->id)->and(CostAdjustment::count())->toBe(1);

    // the user submits again with the SAME key X on the refreshed page (current = v2): different reconciliation ⇒ IDEMPOTENCY CONFLICT, not a new adjustment on v2
    $page->set('adjustKey', $key)->call('adjust')->assertHasErrors(['adjustment.conflict'])->assertSee('IDEMPOTENCY CONFLICT')->assertDontSee('أُضيف التعديل');
    expect(CostAdjustment::count())->toBe(1)->and(CostAdjustment::query()->where('cost_reconciliation_id', $v2->id)->count())->toBe(0)
        ->and($scope->fresh()->current_reconciliation_id)->toBe($v2->id)->and($audits())->toBe(1)->and($page->get('adjustKey'))->toBe($key);

    // the revision history shows X under v1 only; v2 carries no adjustment
    $html = $this->get(route('dashboard.finance.reconciliation.show', $scope->id))->assertOk()->getContent();
    $v1Block = substr($html, strpos($html, 'data-testid="revision-'.$v1->id.'"'));
    $v2Block = substr($html, strpos($html, 'data-testid="revision-'.$v2->id.'"'), strpos($html, 'data-testid="revision-'.$v1->id.'"') - strpos($html, 'data-testid="revision-'.$v2->id.'"'));
    expect(str_contains($v1Block, 'data-testid="adjustment-'.$historical->id.'"'))->toBeTrue()
        ->and(str_contains($v2Block, 'data-testid="adjustment-'))->toBeFalse()
        ->and(str_contains($html, 'rev-adjusted-'.$v2->id.'">0.000000 · 40.000000'))->toBeTrue(); // v2: adjustments 0, adjusted = base
});

it('audit subjects: reconcile / adjust are recorded under CostReconciliationScope#<scope>; the detail link reaches cost.reconciled (reconciliation id) and cost.adjusted (adjustment id); no subject under CostReconciliation / CostAdjustment', function () {
    $fx = closableMonth();
    $finance = userWithRole(Role::Finance);
    $rec = $fx['reconciliation'];
    $scope = CostReconciliationScope::query()->findOrFail($rec->scope_id);

    $rows = AuditLog::query()->whereIn('action', [AuditActions::CostReconciled, AuditActions::CostAdjusted])->where('subject_id', $scope->id)->get(['action', 'subject_type', 'subject_id']);
    expect($rows->map(fn ($r) => [$r->action, class_basename($r->subject_type), $r->subject_id])->unique()->values()->all())->toHaveCount(2)->toContain([AuditActions::CostReconciled, 'CostReconciliationScope', $scope->id])->toContain([AuditActions::CostAdjusted, 'CostReconciliationScope', $scope->id])
        ->and(AuditLog::query()->where(fn ($q) => $q->where('subject_type', 'like', '%CostReconciliation')->orWhere('subject_type', 'like', '%CostAdjustment'))->count())->toBe(0);

    $detail = $this->actingAs($finance)->get(route('dashboard.finance.reconciliation.show', $scope->id))->assertOk()->assertSee('subject CostReconciliationScope #'.$scope->id);
    preg_match('/href="([^"]+)"[^>]*data-testid="audit-link"/', $detail->getContent(), $m);
    $link = html_entity_decode($m[1]);
    expect($link)->toContain('subject_type=CostReconciliationScope')->toContain('subject_id='.$scope->id);
    $audit = $this->get($link)->assertOk()->getContent();
    expect(str_contains($audit, 'cost.reconciled'))->toBeTrue()->and(str_contains($audit, 'cost.adjusted'))->toBeTrue()
        ->and(str_contains($audit, '&quot;to&quot;: '.$rec->id))->toBeTrue() // current_reconciliation_id from → to
        ->and(str_contains($audit, '&quot;id&quot;: '.$fx['adjustment']->id))->toBeTrue(); // the adjustment id inside cost.adjusted

    Spatie\Permission\Models\Role::findByName(Role::Finance->value)->revokePermissionTo('audit.view');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs($finance->fresh())->get(route('dashboard.finance.reconciliation.show', $scope->id))->assertOk()->assertDontSee('data-testid="audit-link"', false);
});

it('refuses every write once the permission is withdrawn mid-session; opening a panel writes nothing', function () {
    $fx = closableMonth();
    $finance = userWithRole(Role::Finance);
    $scope = CostReconciliationScope::query()->findOrFail($fx['reconciliation']->scope_id);
    $pages = [scopePage($finance, $scope)->call('openConfirm', 'adjust')->set('adjAmount', '-1')->set('adjReason', 'x')->set('adjEvidence', 'y'), scopePage($finance, $scope), scopePage($finance, $scope), scopePage($finance, $scope)];
    $finance->removeRole(Role::Finance->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $pages[0]->call('adjust')->assertForbidden();
    $pages[1]->call('confirmZero')->assertForbidden();
    $pages[2]->call('reconcileFromEvidence')->assertForbidden();
    $pages[3]->call('openConfirm', 'manual')->assertForbidden();
    expect(CostAdjustment::count())->toBe(1)->and(CostReconciliation::count())->toBe(3);
});
