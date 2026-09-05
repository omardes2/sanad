<?php

declare(strict_types=1);

use App\Data\Billing\PricePublication;
use App\Data\Billing\UsageRecord;
use App\Enums\CostSource;
use App\Enums\UsageDimension;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\UsageEvent;
use App\Services\Billing\Pricing\PriceBook;
use App\Services\Billing\UsageRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The core Phase B2 guarantee: a usage event's cost is fixed at the price in
 * force when it occurred. Later price changes create new periods and can
 * never alter the cost, price reference or snapshot of an existing event.
 */
function immutabilityModel(): AiModel
{
    $provider = AiProvider::factory()->create(['key' => 'openai']);

    return AiModel::factory()->for($provider, 'provider')->create(['external_id' => 'gpt-4.1-mini']);
}

function immutabilityPublication(string $from, string $input, string $output): PricePublication
{
    return new PricePublication('USD', $input, $output, null, '0', CarbonImmutable::parse($from));
}

function immutabilityRecord(string $key, string $occurredAt): UsageRecord
{
    return new UsageRecord(
        subscriber: billingSubscriber(),
        dimension: UsageDimension::AiReply,
        idempotencyKey: $key,
        provider: 'openai',
        model: 'gpt-4.1-mini',
        inputUnits: 1000,
        outputUnits: 300,
        occurredAt: CarbonImmutable::parse($occurredAt),
    );
}

it('a later price change never alters the cost, price reference or snapshot of an existing event', function () {
    $model = immutabilityModel();
    $book = app(PriceBook::class);
    $first = $book->publish($model, immutabilityPublication('2026-01-01 00:00:00', '0.40', '1.60'));

    $event = app(UsageRecorder::class)->record(immutabilityRecord('ai_reply:message:1#1', '2026-02-01 12:00:00'))->event;

    expect($event->cost_source)->toBe(CostSource::ModelPrice)
        ->and($event->model_price_id)->toBe($first->id)
        ->and((string) $event->provider_cost)->toBe('0.000880');

    // Price goes up tenfold afterwards.
    $second = $book->publish($model, immutabilityPublication('2026-03-01 00:00:00', '4.00', '16.00'));

    $same = UsageEvent::query()->findOrFail($event->id);

    expect((string) $same->provider_cost)->toBe('0.000880')
        ->and((string) $same->total_cost)->toBe('0.000880')
        ->and($same->model_price_id)->toBe($first->id)
        ->and($same->pricing_snapshot['input_per_million'])->toBe('0.40000000')
        ->and($same->pricing_snapshot['price_id'])->toBe($first->id);

    // A NEW event after the change is costed with the new price.
    $later = app(UsageRecorder::class)->record(immutabilityRecord('ai_reply:message:2#1', '2026-03-15 12:00:00'))->event;

    expect($later->model_price_id)->toBe($second->id)
        ->and((string) $later->provider_cost)->toBe('0.008800');
});

it('a retry after a price change cannot rewrite the first recording (idempotency key wins)', function () {
    $model = immutabilityModel();
    $book = app(PriceBook::class);
    $first = $book->publish($model, immutabilityPublication('2026-01-01 00:00:00', '0.40', '1.60'));

    $record = immutabilityRecord('ai_reply:message:9#1', '2026-02-01 12:00:00');
    $original = app(UsageRecorder::class)->record($record);

    $book->publish($model, immutabilityPublication('2026-02-01 13:00:00', '4.00', '16.00'));

    // Replay of the same invocation (job retry) with the same key.
    $replay = app(UsageRecorder::class)->record($record);

    expect($replay->created)->toBeFalse()
        ->and($replay->event->id)->toBe($original->event->id)
        ->and($replay->event->model_price_id)->toBe($first->id)
        ->and((string) $replay->event->provider_cost)->toBe('0.000880')
        ->and(UsageEvent::count())->toBe(1);
});

it('an event that occurred before any price is UNPRICED and stays so after a price is published (no recompute)', function () {
    $model = immutabilityModel();

    $event = app(UsageRecorder::class)->record(immutabilityRecord('ai_reply:message:3#1', '2026-01-10 12:00:00'))->event;

    expect($event->cost_source)->toBe(CostSource::None)
        ->and($event->hasKnownCost())->toBeFalse()
        ->and($event->model_price_id)->toBeNull()
        ->and(UsageEvent::query()->unpriced()->count())->toBe(1)
        ->and(UsageEvent::query()->priced()->count())->toBe(0);

    // Publishing a price later — even backdated to cover the event — does not touch it.
    app(PriceBook::class)->publish($model, immutabilityPublication('2026-01-01 00:00:00', '0.40', '1.60'));

    $same = UsageEvent::query()->findOrFail($event->id);

    expect($same->cost_source)->toBe(CostSource::None)
        ->and($same->model_price_id)->toBeNull()
        ->and((string) $same->provider_cost)->toBe('0.000000')
        ->and(UsageEvent::query()->unpriced()->count())->toBe(1);
});

it('counts pre-B2 rows (cost_source NULL) as unpriced', function () {
    UsageEvent::factory()->create(['cost_source' => null]);
    UsageEvent::factory()->create(['cost_source' => CostSource::ConfigRate]);

    expect(UsageEvent::query()->unpriced()->count())->toBe(1)
        ->and(UsageEvent::query()->priced()->count())->toBe(1);
});
