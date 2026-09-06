<?php

declare(strict_types=1);

use App\Livewire\Dashboard\Finance\RefundDetail;
use App\Livewire\Dashboard\Finance\Refunds;
use App\Models\AuditLog;
use App\Models\PaymentAllocation;
use App\Models\RefundAllocation;
use App\Services\Payments\AllocationService;
use App\Support\Audit\AuditActions;
use App\Support\Rbac\Role;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * Phase E5.2a — /dashboard/finance/refunds and /refunds/{refund}: bounded
 * UTC window on refunded_at, currency and payment filters in the URL, 25 per
 * page; the detail with the original payment, reporting status, allocation
 * history and the remaining attributable amount; Allocate Refund through the
 * E1 service with only the payment's own allocations as targets, caps refused
 * verbatim, one attempt key per attempt, ids only.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));
});

it('lists refunds with bounded window, currency and payment filters, 25 per page in id-desc order, URL-kept, no PII', function () {
    $fx = closableMonth();
    $finance = userWithRole(Role::Finance);
    for ($i = 0; $i < 30; $i++) {
        e1Refund($fx['usd'], ['amount' => '1.00', 'refundedAt' => CarbonImmutable::parse('2026-08-15', 'UTC')->addMinutes($i)]);
    }
    $ilsRefund = e1Refund($fx['ils'], ['amount' => '36.50', 'refundedAt' => CarbonImmutable::parse('2026-08-16', 'UTC')]);

    $page = Livewire::actingAs($finance)->test(Refunds::class, ['from' => '2026-08-01', 'to' => '2026-08-31'])->assertSee('32 rows · page 1 of 2')->assertSee('Refunded (UTC)');
    expect(substr_count($page->html(), 'data-testid="refund-'))->toBe(25);
    $page->call('gotoPage', 2)->assertSee('page 2 of 2');
    $page->set('currency', 'ILS')->assertSee('1 rows · page 1 of 1')->assertSee('data-testid="refund-'.$ilsRefund->id.'"', false);
    $page->set('currency', '')->set('payment', (string) $fx['ils']->id)->assertSee('1 rows');
    $page->set('payment', 'abc')->assertSee('32 rows');
    $page->set('from', '2025-01-01')->assertSee('النطاق الأقصى')->assertSee('0 rows');

    $this->actingAs($finance)->get(route('dashboard.finance.refunds', ['from' => '2026-08-01', 'to' => '2026-08-31', 'currency' => 'ILS']))->assertOk()->assertSee('1 rows · page 1 of 1')
        ->assertSee(route('dashboard.finance.refunds.show', $ilsRefund->id))->assertSee(route('dashboard.finance.payments.show', $fx['ils']->id))
        ->assertDontSee($fx['subscriber']->email)->assertDontSee($fx['subscriber']->name);
});

it('refund detail shows the facts, the original payment, reporting status, allocation history and remaining attributable; audit link only with audit.view', function () {
    $fx = closableMonth();
    $finance = userWithRole(Role::Finance);
    $refund = $fx['refund'];

    $this->actingAs($finance)->get(route('dashboard.finance.refunds.show', $refund->id))->assertOk()
        ->assertSee('data-testid="fact-id"', false)->assertSee('#'.$fx['usd']->id.' · 100.00 USD · succeeded')->assertSee('10.00 USD')->assertSee('2026-08-12 10:00:00')->assertSee('Refunded at (UTC)')
        ->assertSee('NATIVE')->assertSee('0.00 / 10.00')->assertSee('لم يُنسب بعد')->assertSee('data-testid="audit-link"', false)
        ->assertSee(e(route('dashboard.audit', ['subject_type' => 'CustomerPayment', 'subject_id' => $fx['usd']->id])), false)
        ->assertDontSee($fx['subscriber']->email)->assertDontSee($fx['subscriber']->name);

    $viewer = userWithRole(Role::Finance);
    Spatie\Permission\Models\Role::findByName(Role::Finance->value)->revokePermissionTo('audit.view');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs($viewer->fresh())->get(route('dashboard.finance.refunds.show', $refund->id))->assertOk()->assertDontSee('data-testid="audit-link"', false);
    $this->actingAs(userWithRole(Role::SuperAdmin))->get(route('dashboard.finance.refunds.show', 999))->assertNotFound();
});

it('allocates a refund from the detail: only the payment\'s own allocations are targets, remaining reversible shown, caps refused verbatim, same-key double submit refused as duplicate, one audit per success', function () {
    $fx = closableMonth();
    $finance = userWithRole(Role::Finance);
    $refund = $fx['refund']; // 10.00 on the 100.00 USD payment
    $event = periodEvent($fx['subscriber']);
    $allocation = app(AllocationService::class)->allocatePayment($fx['usd']->id, $event->id, '60.00');
    $foreign = app(AllocationService::class)->allocatePayment($fx['ils']->id, periodEvent($fx['subscriber'])->id, '5.00'); // another payment's allocation

    $page = Livewire::actingAs($finance)->test(RefundDetail::class, ['refund' => $refund])->call('openConfirm', 'allocate')
        ->assertSee('data-testid="form-refund-allocation"', false)->assertSee('#'.$allocation->id.' · 2026-09-01 → 2026-10-01 · 60.00 USD · reversible 60.00')->assertDontSee('#'.$foreign->id.' · ');
    $key = $page->get('allocationKey');

    $page->set('rallocAllocationId', (string) $allocation->id)->set('rallocAmount', '15.00')->call('allocateRefund')->assertHasErrors(['refund_allocation.rule'])->assertSee('refund_allocation_limit —');
    $page->set('rallocAllocationId', (string) $foreign->id)->set('rallocAmount', '1.00')->call('allocateRefund')->assertHasErrors(['refund_allocation.rule'])->assertSee('allocation —');
    expect(RefundAllocation::count())->toBe(0)->and($page->get('allocationKey'))->toBe($key);

    $page->set('rallocAllocationId', (string) $allocation->id)->set('rallocAmount', '10.00')->call('allocateRefund')->assertHasNoErrors()->assertSee('نُسب الاسترداد')->assertSee('10.00 / 0.00');
    expect(RefundAllocation::count())->toBe(1)->and($page->get('allocationKey'))->not->toBe($key)
        ->and(AuditLog::where('action', AuditActions::RefundAllocated)->where('subject_id', $fx['usd']->id)->count())->toBe(1)
        ->and((string) PaymentAllocation::query()->findOrFail($allocation->id)->amount)->toBe('60.00'); // the original allocation is never modified

    $page->set('allocationKey', $key)->call('openConfirm', 'allocate')->set('rallocAllocationId', (string) $allocation->id)->set('rallocAmount', '1.00')->call('allocateRefund')->assertHasErrors(['refund_allocation.duplicate']);
    expect(RefundAllocation::count())->toBe(1);
});
