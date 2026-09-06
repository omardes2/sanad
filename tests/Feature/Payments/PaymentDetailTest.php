<?php

declare(strict_types=1);

use App\Enums\CustomerPaymentEventType;
use App\Enums\PaymentSource;
use App\Livewire\Dashboard\Finance\PaymentDetail;
use App\Models\AuditLog;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentEvent;
use App\Models\CustomerRefund;
use App\Models\PaymentAllocation;
use App\Models\SubscriptionEvent;
use App\Models\User;
use App\Services\Payments\CashCollectedQuery;
use App\Services\Payments\CustomerPaymentService;
use App\Support\Audit\AuditActions;
use App\Support\Payments\SubmitAttempt;
use App\Support\Rbac\Role;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * Phase E5.2a — /dashboard/finance/payments/{payment}: facts only (ids,
 * bounded refs, UTC), event trail, refunds, allocations, remaining amounts
 * from the service sums, reporting status, banners, audit link; the E1
 * lifecycle transitions with the rendered token (stale ⇒ refused, refreshed,
 * never re-run); refund and allocation attempts with one key per attempt;
 * caps refused verbatim, never clipped; historical cash never rewritten;
 * one audit entry per successful action, none for refused ones.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));
});

function detailPage(User $user, CustomerPayment $payment)
{
    return Livewire::actingAs($user)->test(PaymentDetail::class, ['payment' => $payment]);
}

it('is reachable only with finance.payments.manage and shows the facts, trail, refunds, allocations, remaining amounts, reporting status and no PII', function () {
    rbacSync();
    $fx = closableMonth();
    $url = route('dashboard.finance.payments.show', $fx['ils']->id);

    $this->get($url)->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create(['is_admin' => true]))->get($url)->assertForbidden();
    $this->actingAs(userWithRole(Role::Operations))->get($url)->assertForbidden();
    $this->actingAs(userWithRole(Role::Support))->get($url)->assertForbidden();
    $this->actingAs(userWithRole(Role::SuperAdmin))->get($url)->assertOk();
    $this->actingAs(userWithRole(Role::SuperAdmin))->get(route('dashboard.finance.payments.show', 999))->assertNotFound();

    $finance = userWithRole(Role::Finance);
    $html = $this->actingAs($finance)->get(route('dashboard.finance.payments.show', $fx['usd']->id))->assertOk()
        ->assertSee('data-testid="fact-id"', false)->assertSee('#'.$fx['subscriber']->id)->assertSee('manual')->assertSee('100.00 USD')->assertSee('2026-08-10 09:00:00')
        ->assertSee('succeeded')->assertSee('3.00 USD (known)')->assertSee('NATIVE')->assertSee('e:'.$fx['usd']->latest_event_id)
        ->assertSee('10.00 / 90.00') // refunded / remaining refundable
        ->assertSee('0.00 / 100.00') // allocated / remaining allocatable
        ->assertSee('data-testid="refund-'.$fx['refund']->id.'"', false)->assertSee(route('dashboard.finance.refunds.show', $fx['refund']->id))
        ->assertSee('Received at (UTC)')->assertSee('Occurred (UTC)')
        ->assertDontSee($fx['subscriber']->email)->assertDontSee($fx['subscriber']->name)->assertDontSee('Revenue:')->assertDontSee('Gross Profit:')
        ->getContent();
    $trail = substr($html, strpos($html, 'data-testid="section-events"'));
    expect(substr_count($trail, 'data-testid="event-'))->toBe(2)->and($trail)->toContain('created')->toContain('succeeded'); // trail = created + succeeded

    // ILS payment: converted, fee known, reporting facts shown with the frozen rate.
    $this->actingAs($finance)->get(route('dashboard.finance.payments.show', $fx['ils']->id))->assertOk()
        ->assertSee('CONVERTED · 100.00 USD')->assertSee('rate #'.$fx['rate']->id.' · 2026-08-10 · 3.650000000000 · inverse')->assertSee('3.65 ILS (known)');
});

it('shows the shared banners from the service states only: FEES UNKNOWN, NOT CONVERTED, UNRESOLVED DISPUTE — nothing on a clean payment', function () {
    $fx = closableMonth();
    $finance = userWithRole(Role::Finance);
    $eur = e1Payment($fx['subscriber'], ['amount' => '50.00', 'currency' => 'EUR', 'receivedAt' => CarbonImmutable::parse('2026-08-21', 'UTC')]);

    $clean = $this->actingAs($finance)->get(route('dashboard.finance.payments.show', $fx['usd']->id))->assertOk()->getContent();
    expect(str_contains($clean, 'data-testid="payment-banners"'))->toBeFalse();

    $this->actingAs($finance)->get(route('dashboard.finance.payments.show', $eur->id))->assertOk()
        ->assertSee('WARNING · FEES UNKNOWN')->assertSee('WARNING · NOT CONVERTED · no current frozen conversion EUR → USD')->assertDontSee('UNRESOLVED DISPUTE');

    app(CustomerPaymentService::class)->transition($fx['usd'], CustomerPaymentEventType::Disputed, $fx['usd']->stateToken(), PaymentSource::Gateway, 'chargeback');
    $this->actingAs($finance)->get(route('dashboard.finance.payments.show', $fx['usd']->id))->assertOk()->assertSee('WARNING · UNRESOLVED DISPUTE')->assertSee('period close blocked (UNRESOLVED_DISPUTES)');
});

it('audit link appears only with audit.view and points at the payment subject; the audit page still requires audit.view', function () {
    $fx = closableMonth();
    $url = route('dashboard.audit', ['subject_type' => 'CustomerPayment', 'subject_id' => $fx['usd']->id]);
    $this->actingAs(userWithRole(Role::Finance))->get(route('dashboard.finance.payments.show', $fx['usd']->id))->assertOk()->assertSee('data-testid="audit-link"', false)->assertSee(e($url), false);
    $this->actingAs(userWithRole(Role::Finance))->get($url)->assertOk()->assertSee('payment.recorded')->assertSee('CustomerPayment#'.$fx['usd']->id);

    $viewer = userWithRole(Role::Finance);
    Spatie\Permission\Models\Role::findByName(Role::Finance->value)->revokePermissionTo('audit.view');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs($viewer->fresh())->get(route('dashboard.finance.payments.show', $fx['usd']->id))->assertOk()->assertDontSee('data-testid="audit-link"', false);
    $this->actingAs($viewer->fresh())->get($url)->assertForbidden();
});

it('dispute lifecycle: only the legal transition is offered, dispute then resolve run through the service with the rendered token, historical cash and the succeeded event are untouched, one audit per transition, the service refuses an illegal transition even when called directly', function () {
    $fx = closableMonth();
    $finance = userWithRole(Role::Finance);
    $payment = $fx['usd'];
    $window = [CarbonImmutable::parse('2026-08-01', 'UTC'), CarbonImmutable::parse('2026-09-01', 'UTC')];
    $grossBefore = app(CashCollectedQuery::class)->summarise(...$window)['USD']->grossCashCollected;
    $audits = fn () => AuditLog::where('action', AuditActions::PaymentTransitioned)->where('subject_id', $payment->id)->count();

    $page = detailPage($finance, $payment)->assertSee('data-testid="open-dispute"', false)->assertDontSee('data-testid="open-resolve"', false)
        ->call('openConfirm', 'dispute')->assertSee('data-testid="confirm-dispute"', false)->assertSee('Confirm DISPUTE on payment #'.$payment->id);

    // reason is required by the UI; nothing written
    $page->call('dispute')->assertHasErrors(['dispute.validation']);
    expect($audits())->toBe(0);

    $page->set('transitionReason', 'chargeback')->call('dispute')->assertHasNoErrors()->assertSee('الحالة الآن disputed')
        ->assertDontSee('data-testid="open-dispute"', false)->assertSee('data-testid="open-resolve"', false)->assertSee('WARNING · UNRESOLVED DISPUTE')->assertSee('data-testid="refund-unavailable"', false);
    $payment->refresh();
    expect($payment->current_status)->toBe(CustomerPaymentEventType::Disputed)->and($audits())->toBe(1)
        ->and(CustomerPaymentEvent::query()->where('customer_payment_id', $payment->id)->pluck('event_type')->map(fn ($e) => $e->value)->all())->toBe(['created', 'succeeded', 'disputed'])
        ->and(app(CashCollectedQuery::class)->summarise(...$window)['USD']->grossCashCollected)->toBe($grossBefore) // historical cash unchanged
        ->and((string) $payment->amount)->toBe('100.00')->and(CustomerPayment::count())->toBe(2);

    // The service is the authority: an illegal transition called directly is refused by rule, not by a hidden button.
    $page->call('openConfirm', 'dispute')->set('transitionReason', 'again')->call('dispute')->assertHasErrors(['dispute.rule'])->assertSee('lifecycle —');
    expect($audits())->toBe(1)->and(CustomerPaymentEvent::query()->where('customer_payment_id', $payment->id)->count())->toBe(3);

    $page->call('openConfirm', 'resolve')->set('transitionReason', 'won')->call('resolveDispute')->assertHasNoErrors()->assertSee('الحالة الآن dispute_resolved')->assertSee('data-testid="no-transition"', false);
    expect($payment->fresh()->current_status)->toBe(CustomerPaymentEventType::DisputeResolved)->and($audits())->toBe(2)->and(CustomerPayment::count())->toBe(2) // resolve creates no payment
        ->and(CustomerPaymentEvent::query()->where('customer_payment_id', $payment->id)->where('event_type', 'succeeded')->count())->toBe(1)
        ->and(app(CashCollectedQuery::class)->summarise(...$window)['USD']->grossCashCollected)->toBe($grossBefore);
});

it('stale token: a lifecycle change behind the page makes dispute, refund and allocation refuse with "State changed", refresh the token, write nothing and never re-run; the user retries on purpose', function () {
    $fx = closableMonth();
    $finance = userWithRole(Role::Finance);
    $payment = $fx['usd'];
    periodEvent($fx['subscriber']);

    $page = detailPage($finance, $payment);
    $rendered = $page->get('paymentToken');

    // Another actor disputes the payment while the page is open.
    app(CustomerPaymentService::class)->transition($payment->fresh(), CustomerPaymentEventType::Disputed, $rendered, PaymentSource::Gateway, 'chargeback');
    $fresh = $payment->fresh()->stateToken();
    expect($fresh)->not->toBe($rendered);

    // dispute with the stale token ⇒ refused by the SERVICE (stale), token refreshed, no new event, no audit from this page.
    $page->call('openConfirm', 'dispute')->set('transitionReason', 'x')->call('dispute')->assertHasErrors(['dispute.stale'])->assertSee('State changed — review the refreshed record and try again');
    expect($page->get('paymentToken'))->toBe($fresh)->and(CustomerPaymentEvent::query()->where('customer_payment_id', $payment->id)->count())->toBe(3)
        ->and(AuditLog::where('action', AuditActions::PaymentTransitioned)->where('subject_id', $payment->id)->count())->toBe(1);

    // refund / allocation carry the rendered token too: stale ⇒ refused before the service, nothing written, keys unchanged.
    $page->set('paymentToken', $rendered);
    $refundKey = $page->get('refundKey');
    $page->call('openConfirm', 'refund')->set('refundAmount', '5.00')->set('refundReasonCode', 'goodwill')->call('recordRefund')->assertHasErrors(['refund.stale']);
    expect(CustomerRefund::query()->where('customer_payment_id', $payment->id)->count())->toBe(1)->and($page->get('refundKey'))->toBe($refundKey)->and($page->get('paymentToken'))->toBe($fresh);
    $page->set('paymentToken', $rendered)->call('openConfirm', 'allocate')->set('allocEventId', (string) SubscriptionEvent::query()->firstOrFail()->id)->set('allocAmount', '10.00')->call('allocatePayment')->assertHasErrors(['allocation.stale']);
    expect(PaymentAllocation::count())->toBe(0);

    // The user retries on purpose with the refreshed token: the refused-by-status rule now comes from the service (disputed ⇒ no refund), still nothing auto-retried.
    $page->call('openConfirm', 'refund')->set('refundAmount', '5.00')->set('refundReasonCode', 'goodwill')->call('recordRefund')->assertHasErrors(['refund.rule']);
    expect(CustomerRefund::query()->where('customer_payment_id', $payment->id)->count())->toBe(1);
});

it('refund attempt: prefilled payment, remaining refundable shown from the service sums, one key per attempt kept across refusals and rotated after success, double submit with the same key is one refund, cap refused verbatim with no clipping, one audit', function () {
    $fx = closableMonth();
    $finance = userWithRole(Role::Finance);
    $payment = $fx['usd']; // 100.00, already refunded 10.00
    $page = detailPage($finance, $payment)->assertSee('10.00 / 90.00')->call('openConfirm', 'refund')->assertSee('data-testid="form-refund"', false);
    $key = $page->get('refundKey');

    // cap: 95 > remaining 90 ⇒ REFUSED BY SERVICE (refund_limit), nothing written, key kept
    $page->set('refundAmount', '95.00')->set('refundReasonCode', 'goodwill')->call('recordRefund')->assertHasErrors(['refund.rule'])->assertSee('refund_limit —')->assertSee('يتجاوز مبلغ الدفعة');
    expect(CustomerRefund::query()->where('customer_payment_id', $payment->id)->count())->toBe(1)->and($page->get('refundKey'))->toBe($key);

    // success rotates the key; remaining updates from the same sums
    $page->set('refundAmount', '40.00')->call('recordRefund')->assertHasNoErrors()->assertSee('سُجِّل الاسترداد')->assertSee('50.00 / 50.00');
    $refund = CustomerRefund::query()->where('idempotency_key', $key)->firstOrFail();
    expect((string) $refund->amount)->toBe('40.00')->and($page->get('refundKey'))->not->toBe($key)
        ->and(AuditLog::where('action', AuditActions::PaymentRefunded)->where('subject_id', $payment->id)->count())->toBe(2); // fixture refund + this one; none for the refused attempt

    // double submit: the same key and the same facts again ⇒ the same refund, no second row, no second audit
    $page->set('refundKey', $key)->call('openConfirm', 'refund')->set('refundAmount', '40.00')->set('refundReasonCode', 'goodwill')->set('refundedAt', $refund->refunded_at->utc()->format('Y-m-d\TH:i'))
        ->call('recordRefund')->assertHasNoErrors()->assertSee('مسجَّل مسبقًا');
    expect(CustomerRefund::query()->where('customer_payment_id', $payment->id)->count())->toBe(2)->and(AuditLog::where('action', AuditActions::PaymentRefunded)->where('subject_id', $payment->id)->count())->toBe(2);

    // the same key + different facts ⇒ conflict; the UI does not mint a new key to get around it
    $page->set('refundKey', $key)->call('openConfirm', 'refund')->set('refundAmount', '41.00')->set('refundReasonCode', 'goodwill')->set('refundedAt', $refund->refunded_at->utc()->format('Y-m-d\TH:i'))
        ->call('recordRefund')->assertHasErrors(['refund.conflict'])->assertSee('IDEMPOTENCY CONFLICT');
    expect($page->get('refundKey'))->toBe($key)->and(CustomerRefund::query()->where('customer_payment_id', $payment->id)->count())->toBe(2);
});

it('allocation attempt: only the subscriber\'s events with a valid period are offered, cap refused verbatim, success rotates the key; a same-key replay returns the same row (no second row, no second audit) and a same-key different payload is an idempotency conflict — without any cache claim', function () {
    $fx = closableMonth();
    $finance = userWithRole(Role::Finance);
    $payment = $fx['usd'];
    $event = periodEvent($fx['subscriber']);
    $other = periodEvent(billingSubscriber()); // another subscriber — never offered
    $noPeriod = SubscriptionEvent::query()->create(['subscription_id' => $event->subscription_id, 'subscriber_id' => $fx['subscriber']->id, 'event_type' => 'baseline', 'to_status' => 'active', 'effective_at' => now(), 'source' => 'baseline', 'actor_ref' => 'console']);

    $page = detailPage($finance, $payment)->call('openConfirm', 'allocate')->assertSee('data-testid="form-allocation"', false)
        ->assertSee('#'.$event->id.' · extended')->assertDontSee('#'.$other->id.' · extended')->assertDontSee('#'.$noPeriod->id.' · baseline');
    $key = $page->get('allocationKey');

    $page->set('allocEventId', (string) $event->id)->set('allocAmount', '150.00')->call('allocatePayment')->assertHasErrors(['allocation.rule'])->assertSee('allocation_limit —');
    expect(PaymentAllocation::count())->toBe(0)->and($page->get('allocationKey'))->toBe($key);

    // the service refuses another subscriber's event even if the id is forced into the form
    $page->set('allocEventId', (string) $other->id)->set('allocAmount', '10.00')->call('allocatePayment')->assertHasErrors(['allocation.rule'])->assertSee('subscriber_mismatch —');

    $page->set('allocEventId', (string) $event->id)->set('allocAmount', '60.00')->call('allocatePayment')->assertHasNoErrors()->assertSee('خُصِّص')->assertSee('60.00 / 40.00');
    expect(PaymentAllocation::count())->toBe(1)->and($page->get('allocationKey'))->not->toBe($key);

    $allocationId = PaymentAllocation::query()->firstOrFail()->id;
    $audits = fn () => AuditLog::where('action', AuditActions::PaymentAllocated)->where('subject_id', $payment->id)->count();

    // The claim was released after success (UX guard only): a replay of the SAME attempt key + the same facts reaches the
    // service, which returns the SAME row — no second row, no second audit. The DB unique key is the authority, not the cache.
    $page->set('allocationKey', $key)->call('openConfirm', 'allocate')->set('allocEventId', (string) $event->id)->set('allocAmount', '60.00')->call('allocatePayment')
        ->assertHasNoErrors()->assertSee('مسجَّل مسبقًا بنفس المفتاح')->assertSee('#'.$allocationId);
    expect(PaymentAllocation::count())->toBe(1)->and($audits())->toBe(1)->and($page->get('allocationKey'))->not->toBe($key);

    // Same key + a different payload (10.00 instead of 60.00) ⇒ IDEMPOTENCY CONFLICT: nothing written, the key is kept, no new key is minted.
    $page->set('allocationKey', $key)->call('openConfirm', 'allocate')->set('allocEventId', (string) $event->id)->set('allocAmount', '10.00')->call('allocatePayment')
        ->assertHasErrors(['allocation.conflict'])->assertSee('IDEMPOTENCY CONFLICT')->assertSee('بحقائق مختلفة');
    expect(PaymentAllocation::count())->toBe(1)->and($audits())->toBe(1)->and($page->get('allocationKey'))->toBe($key);

    // A genuine double-click (the claim still held) is still stopped before the service by the UX guard.
    expect(SubmitAttempt::claim('allocation', $key))->toBeTrue();
    $page->set('allocAmount', '60.00')->call('allocatePayment')->assertHasErrors(['allocation.duplicate'])->assertSee('DUPLICATE SUBMIT');
    SubmitAttempt::release('allocation', $key);
    expect(PaymentAllocation::count())->toBe(1)->and($audits())->toBe(1);
});

it('refuses every action once the permission is withdrawn mid-session, and opening a confirmation panel writes nothing', function () {
    $fx = closableMonth();
    $finance = userWithRole(Role::Finance);
    $page = detailPage($finance, $fx['usd'])->call('openConfirm', 'dispute')->assertSee('data-testid="confirm-dispute"', false)->call('closeConfirm')->assertDontSee('data-testid="confirm-dispute"', false);

    $pages = [detailPage($finance, $fx['usd'])->set('transitionReason', 'x'), detailPage($finance, $fx['usd']), detailPage($finance, $fx['usd']), $page];
    $finance->removeRole(Role::Finance->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $pages[0]->call('dispute')->assertForbidden();
    $pages[1]->call('recordRefund')->assertForbidden();
    $pages[2]->call('allocatePayment')->assertForbidden();
    $pages[3]->call('openConfirm', 'refund')->assertForbidden();
    expect(CustomerPaymentEvent::query()->where('customer_payment_id', $fx['usd']->id)->count())->toBe(2)->and(PaymentAllocation::count())->toBe(0);
});
