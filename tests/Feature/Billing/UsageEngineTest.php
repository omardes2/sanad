<?php

declare(strict_types=1);

use App\Enums\UsageDimension;
use App\Enums\UsageOutcome;
use App\Models\UsageCharge;
use App\Models\UsageCounter;
use App\Models\UsageEvent;
use App\Services\Billing\UsageEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['billing.enforce' => true]);
});

function engine(): UsageEngine
{
    return app(UsageEngine::class);
}

it('consumes quota: charging increments counters and logs one charge row — never the cost ledger', function () {
    $subscriber = billingSubscriber(billingPlan(['daily' => 5, 'monthly' => 50]));

    $decision = engine()->charge($subscriber, UsageDimension::AiReply, 'k1');

    expect($decision->outcome)->toBe(UsageOutcome::Allowed)
        ->and(engine()->usage($subscriber, UsageDimension::AiReply))->toBe(['daily' => 1, 'monthly' => 1])
        ->and(UsageCharge::where('idempotency_key', 'k1')->count())->toBe(1)
        // Enforcement never writes usage_events; that is UsageRecorder's job.
        ->and(UsageEvent::count())->toBe(0);
});

it('enforces the daily limit', function () {
    $subscriber = billingSubscriber(billingPlan(['daily' => 2, 'monthly' => 50]));

    engine()->charge($subscriber, UsageDimension::AiReply, 'a');
    engine()->charge($subscriber, UsageDimension::AiReply, 'b');
    $third = engine()->charge($subscriber, UsageDimension::AiReply, 'c');

    expect($third->outcome)->toBe(UsageOutcome::LimitReached)
        ->and($third->window)->toBe('day')
        ->and(engine()->usage($subscriber, UsageDimension::AiReply)['daily'])->toBe(2); // never exceeds cap
});

it('enforces the monthly limit', function () {
    $subscriber = billingSubscriber(billingPlan(['daily' => null, 'monthly' => 1]));

    engine()->charge($subscriber, UsageDimension::AiReply, 'a');
    $second = engine()->charge($subscriber, UsageDimension::AiReply, 'b');

    expect($second->outcome)->toBe(UsageOutcome::LimitReached)
        ->and($second->window)->toBe('month');
});

it('allows unlimited dimensions (null caps) without blocking', function () {
    $subscriber = billingSubscriber(billingPlan(['daily' => null, 'monthly' => null]));

    for ($i = 0; $i < 20; $i++) {
        expect(engine()->charge($subscriber, UsageDimension::AiReply, "u{$i}")->allowed())->toBeTrue();
    }

    // No counter rows are created for uncapped windows; each charge is still logged.
    expect(UsageCounter::count())->toBe(0)
        ->and(UsageCharge::count())->toBe(20);
});

it('denies a dimension the plan does not include (disabled)', function () {
    // Plan meters ai_reply but NOT voice.
    $subscriber = billingSubscriber(billingPlan(['daily' => 5, 'monthly' => 50]));

    $decision = engine()->charge($subscriber, UsageDimension::VoiceMinute, 'v1');

    expect($decision->outcome)->toBe(UsageOutcome::Disabled)
        ->and(UsageCharge::count())->toBe(0);
});

it('denies when the subscriber has no entitled subscription', function () {
    $subscriber = billingSubscriber(null); // no subscription

    expect(engine()->charge($subscriber, UsageDimension::AiReply, 'x')->outcome)
        ->toBe(UsageOutcome::Disabled);
});

it('is idempotent: the same key charges only once', function () {
    $subscriber = billingSubscriber(billingPlan(['daily' => 5, 'monthly' => 50]));

    engine()->charge($subscriber, UsageDimension::AiReply, 'dup');
    $again = engine()->charge($subscriber, UsageDimension::AiReply, 'dup');

    expect($again->outcome)->toBe(UsageOutcome::AlreadyCharged)
        ->and($again->allowed())->toBeTrue()
        ->and(engine()->usage($subscriber, UsageDimension::AiReply)['daily'])->toBe(1)
        ->and(UsageCharge::where('idempotency_key', 'dup')->count())->toBe(1);
});

it('isolates usage between subscribers', function () {
    $plan = billingPlan(['daily' => 5, 'monthly' => 50]);
    $a = billingSubscriber($plan);
    $b = billingSubscriber($plan);

    engine()->charge($a, UsageDimension::AiReply, 'a1');
    engine()->charge($a, UsageDimension::AiReply, 'a2');

    expect(engine()->usage($a, UsageDimension::AiReply)['daily'])->toBe(2)
        ->and(engine()->usage($b, UsageDimension::AiReply)['daily'])->toBe(0);
});

it('does not enforce or consume quota when billing.enforce is off', function () {
    config(['billing.enforce' => false]);
    $subscriber = billingSubscriber(billingPlan(['daily' => 1, 'monthly' => 1]));

    engine()->charge($subscriber, UsageDimension::AiReply, 'n1');
    $second = engine()->charge($subscriber, UsageDimension::AiReply, 'n2');

    expect($second->outcome)->toBe(UsageOutcome::NotEnforced)
        ->and(UsageCharge::count())->toBe(0)
        ->and(UsageCounter::count())->toBe(0);
});

it('never exceeds the hard cap across sequential charges (enforcement invariant)', function () {
    $subscriber = billingSubscriber(billingPlan(['daily' => 3, 'monthly' => 100]));

    $allowed = 0;
    for ($i = 0; $i < 10; $i++) {
        if (engine()->charge($subscriber, UsageDimension::AiReply, "seq{$i}")->outcome === UsageOutcome::Allowed) {
            $allowed++;
        }
    }

    expect($allowed)->toBe(3) // exactly the cap
        ->and(engine()->usage($subscriber, UsageDimension::AiReply)['daily'])->toBe(3);
});
