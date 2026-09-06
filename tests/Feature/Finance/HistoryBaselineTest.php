<?php

declare(strict_types=1);

use App\Enums\PlanPriceVersionSource;
use App\Enums\SubscriptionEventSource;
use App\Enums\SubscriptionEventType;
use App\Enums\SubscriptionStatus;
use App\Models\AuditLog;
use App\Models\PlanPriceVersion;
use App\Models\SubscriptionEvent;
use App\Services\Billing\PlanPriceBook;
use App\Support\Audit\AuditActions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Console\Exception\InvalidOptionException;

uses(RefreshDatabase::class);

/**
 * Phase E0 — `sanad:finance:history-baseline`: dry-run by default, --apply
 * explicit, idempotent, stamped with the capture instant, never back-dated.
 */
it('is a dry run by default and writes nothing', function () {
    billingSubscriber(billingPlan());

    $this->artisan('sanad:finance:history-baseline')
        ->expectsOutputToContain('Dry run — nothing written')
        ->assertSuccessful();

    expect(SubscriptionEvent::query()->count())->toBe(0)
        ->and(PlanPriceVersion::query()->count())->toBe(0)
        ->and(AuditLog::where('action', AuditActions::FinanceHistoryBaselineApplied)->count())->toBe(0);
});

it('captures baseline events and price versions as of NOW, from NULL, and is idempotent on re-run', function () {
    $basic = billingPlan(attrs: ['slug' => 'basic', 'price' => '10.00', 'currency' => 'USD', 'billing_period' => 'monthly']);
    $ils = billingPlan(attrs: ['slug' => 'ils', 'price' => '35.00', 'currency' => 'ILS', 'billing_period' => 'yearly']);
    $old = CarbonImmutable::parse('2026-01-15 09:00:00', 'UTC');
    $a = billingSubscriber($basic, ['started_at' => $old, 'current_period_start' => $old]);
    $b = billingSubscriber($ils, ['status' => SubscriptionStatus::PastDue, 'started_at' => $old]);
    // A plan whose history already started stays untouched.
    $versioned = billingPlan(attrs: ['slug' => 'versioned', 'price' => '1.00']);
    app(PlanPriceBook::class)->recordVersion($versioned, CarbonImmutable::now()->subDay(), PlanPriceVersionSource::Admin);

    $before = CarbonImmutable::now('UTC');
    $this->artisan('sanad:finance:history-baseline', ['--apply' => true])
        ->expectsOutputToContain('Captured 2 baseline event(s) and 2 plan price version(s)')
        ->assertSuccessful();

    $events = SubscriptionEvent::query()->orderBy('subscription_id')->get();
    $eventA = $events->firstWhere('subscription_id', $a->subscription->id);
    $eventB = $events->firstWhere('subscription_id', $b->subscription->id);

    expect($events)->toHaveCount(2)
        ->and($eventA->event_type)->toBe(SubscriptionEventType::Baseline)
        ->and($eventA->source)->toBe(SubscriptionEventSource::Baseline)
        ->and($eventA->from_status)->toBeNull()->and($eventA->from_plan_id)->toBeNull() // the past is never invented
        ->and($eventA->to_status)->toBe(SubscriptionStatus::Active)->and($eventA->to_plan_id)->toBe($basic->id)
        ->and($eventA->effective_at->greaterThanOrEqualTo($before->subSecond()))->toBeTrue() // capture instant, NOT started_at (January)
        ->and($eventA->effective_at->year)->toBe($before->year)->and($eventA->effective_at->month)->toBe($before->month)
        ->and($eventA->from_period_start)->toBeNull()->and($eventA->from_period_end)->toBeNull() // nothing before the baseline is guessed
        ->and($eventA->to_period_start->equalTo($a->subscription->current_period_start))->toBeTrue()
        ->and($eventA->to_period_end->equalTo($a->subscription->current_period_end))->toBeTrue()
        ->and($eventA->baseline_key)->toBe('sub:'.$a->subscription->id)
        ->and($eventA->actor_ref)->toBe('console')
        ->and($eventB->to_status)->toBe(SubscriptionStatus::PastDue)->and($eventB->to_plan_id)->toBe($ils->id);

    $versions = PlanPriceVersion::query()->whereNull('effective_until')->get()->keyBy('plan_id');

    expect(PlanPriceVersion::query()->count())->toBe(3)
        ->and($versions[$basic->id]->source)->toBe(PlanPriceVersionSource::Baseline)
        ->and((string) $versions[$basic->id]->price)->toBe('10.00')
        ->and($versions[$basic->id]->effective_from->greaterThanOrEqualTo($before->subSecond()))->toBeTrue()
        ->and($versions[$ils->id]->currency)->toBe('ILS')->and($versions[$ils->id]->billing_period->value)->toBe('yearly')
        ->and($versions[$versioned->id]->source)->toBe(PlanPriceVersionSource::Admin) // untouched
        ->and(AuditLog::where('action', AuditActions::FinanceHistoryBaselineApplied)->count())->toBe(1)
        ->and(AuditLog::where('action', AuditActions::FinanceHistoryBaselineApplied)->first()->metadata['context']['subscription_events'])->toBe(2);

    // Re-run: nothing to do, nothing written, no new audit.
    $this->artisan('sanad:finance:history-baseline', ['--apply' => true])
        ->expectsOutputToContain('Baseline already captured — nothing written.')
        ->assertSuccessful();

    expect(SubscriptionEvent::query()->count())->toBe(2)
        ->and(PlanPriceVersion::query()->count())->toBe(3)
        ->and(AuditLog::where('action', AuditActions::FinanceHistoryBaselineApplied)->count())->toBe(1);
});

it('baselines only subscriptions that have no baseline yet (later subscriptions get their own history)', function () {
    $plan = billingPlan();
    billingSubscriber($plan);
    $this->artisan('sanad:finance:history-baseline', ['--apply' => true])->assertSuccessful();

    $late = billingSubscriber($plan); // created directly (no service) after the baseline
    $this->artisan('sanad:finance:history-baseline', ['--apply' => true])
        ->expectsOutputToContain('Captured 1 baseline event(s) and 0 plan price version(s)')
        ->assertSuccessful();

    expect(SubscriptionEvent::query()->where('subscription_id', $late->subscription->id)->sole()->event_type)->toBe(SubscriptionEventType::Baseline)
        ->and(SubscriptionEvent::query()->count())->toBe(2);
});

it('has no date or backdate option', function () {
    expect(fn () => $this->artisan('sanad:finance:history-baseline', ['--date' => '2026-01-01']))
        ->toThrow(InvalidOptionException::class);
    expect(fn () => $this->artisan('sanad:finance:history-baseline', ['--allow-backdate' => true]))
        ->toThrow(InvalidOptionException::class);
});
