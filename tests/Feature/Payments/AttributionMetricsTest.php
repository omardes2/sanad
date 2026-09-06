<?php

declare(strict_types=1);

use App\Data\Payments\CashSummary;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Services\Payments\AllocationService;
use App\Services\Payments\CashCollectedQuery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Phase E1 — the attribution metrics, by definition:
 *   Net Cash                         = Gross Cash Collected − Refunds
 *   Net Allocated Amount             = Payment Allocations − Refund Allocations
 *   Unallocated Gross Collected Amount = Gross Cash Collected − Payment Allocations
 * A refund never changes the unallocated figure: the historical payment
 * allocation is never erased. None of these is revenue.
 */
it('reports payment 100 / allocation 70 / refund 40 / refund allocation 20 as Gross 100, Refunds 40, Net 60, Allocated 70, Refund Allocated 20, Net Allocated 50, Unallocated 30', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));
    $subscriber = billingSubscriber(billingPlan());
    $subscription = Subscription::query()->where('subscriber_id', $subscriber->id)->firstOrFail();
    $event = SubscriptionEvent::query()->create(['subscription_id' => $subscription->id, 'subscriber_id' => $subscriber->id, 'event_type' => 'extended', 'from_status' => 'active', 'to_status' => 'active', 'to_period_start' => CarbonImmutable::parse('2026-08-01', 'UTC'), 'to_period_end' => CarbonImmutable::parse('2026-09-01', 'UTC'), 'effective_at' => now(), 'source' => 'admin', 'actor_ref' => 'console']);
    $window = fn (): CashSummary => app(CashCollectedQuery::class)->summarise(CarbonImmutable::parse('2026-08-01', 'UTC'), CarbonImmutable::parse('2026-09-01', 'UTC'))['USD'];

    $payment = e1Payment($subscriber, ['amount' => '100.00', 'receivedAt' => CarbonImmutable::parse('2026-08-10 09:00:00', 'UTC')]);
    $allocation = app(AllocationService::class)->allocatePayment($payment->id, $event->id, '70.00', e1Key());

    $beforeRefund = $window();
    expect($beforeRefund->grossCashCollected)->toBe('100.00')->and($beforeRefund->refunds)->toBe('0.00')->and($beforeRefund->netCash)->toBe('100.00')
        ->and($beforeRefund->allocatedCollectedAmount)->toBe('70.00')->and($beforeRefund->refundAllocatedAmount)->toBe('0.00')
        ->and($beforeRefund->netAllocatedAmount)->toBe('70.00')->and($beforeRefund->unallocatedGrossCollectedAmount)->toBe('30.00');

    $refund = e1Refund($payment, ['amount' => '40.00', 'refundedAt' => CarbonImmutable::parse('2026-08-20 09:00:00', 'UTC')]);
    app(AllocationService::class)->allocateRefund($refund->id, $allocation->id, '20.00', e1Key());

    $after = $window();
    expect($after->grossCashCollected)->toBe('100.00')
        ->and($after->refunds)->toBe('40.00')
        ->and($after->netCash)->toBe('60.00') // 100 − 40
        ->and($after->allocatedCollectedAmount)->toBe('70.00')
        ->and($after->refundAllocatedAmount)->toBe('20.00')
        ->and($after->netAllocatedAmount)->toBe('50.00') // 70 − 20
        ->and($after->unallocatedGrossCollectedAmount)->toBe('30.00') // 100 − 70: the refund changes nothing here
        ->and((string) $allocation->fresh()->amount)->toBe('70.00');
});

it('never labels any cash or attribution figure as revenue', function () {
    $names = array_map(fn (ReflectionProperty $p) => strtolower($p->getName()), (new ReflectionClass(CashSummary::class))->getProperties());

    expect($names)->toContain('grosscashcollected', 'refunds', 'netcash', 'allocatedcollectedamount', 'refundallocatedamount', 'netallocatedamount', 'unallocatedgrosscollectedamount')
        ->and(array_filter($names, fn (string $n) => str_contains($n, 'revenue') || str_contains($n, 'profit') || str_contains($n, 'margin')))->toBe([]);

    foreach (['app/Data/Payments/CashSummary.php', 'app/Services/Payments/CashCollectedQuery.php', 'resources/views/livewire/dashboard/finance/payments.blade.php'] as $file) {
        // The only permitted mention is the explicit "Revenue Recognition: NOT AVAILABLE" disclaimer / "never revenue" comments.
        $mentions = preg_match_all('/revenue/i', (string) file_get_contents(base_path($file)), $m);
        $allowed = preg_match_all('/never revenue|Revenue Recognition: <strong>NOT AVAILABLE/i', (string) file_get_contents(base_path($file)), $m2);
        expect($mentions)->toBe($allowed, $file);
    }
});
