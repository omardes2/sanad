<?php

declare(strict_types=1);

use App\Data\Billing\UsageRecord;
use App\Enums\CostSource;
use App\Enums\UsageDimension;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\ModelPrice;
use App\Services\Billing\Pricing\CostCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function pricedModel(array $price = [], array $model = []): AiModel
{
    $provider = AiProvider::factory()->create(['key' => 'openai']);
    $aiModel = AiModel::factory()->for($provider, 'provider')->create(array_merge(['external_id' => 'gpt-4.1-mini'], $model));

    ModelPrice::factory()->for($aiModel, 'model')->create(array_merge([
        'input_per_million' => '0.40000000',
        'output_per_million' => '1.60000000',
        'cached_input_per_million' => '0.10000000',
        'per_request' => '0.00000000',
        'effective_from' => CarbonImmutable::parse('2026-01-01 00:00:00'),
    ], $price));

    return $aiModel;
}

function costRecord(array $overrides = []): UsageRecord
{
    return new UsageRecord(...array_merge([
        'subscriber' => billingSubscriber(),
        'dimension' => UsageDimension::AiReply,
        'idempotencyKey' => 'k-'.uniqid(),
        'provider' => 'openai',
        'model' => 'gpt-4.1-mini',
        'inputUnits' => 1000,
        'outputUnits' => 300,
        'cachedUnits' => 0,
    ], $overrides));
}

it('costs tokens at the model price with exact fixed-point arithmetic', function () {
    pricedModel();

    // 1000 × 0.40/1M + 300 × 1.60/1M = 0.0004 + 0.00048 = 0.00088
    $cost = app(CostCalculator::class)->calculate(costRecord(), CarbonImmutable::parse('2026-02-01 00:00:00'));

    expect($cost->source)->toBe(CostSource::ModelPrice)
        ->and($cost->providerCost)->toBe('0.000880')
        ->and($cost->totalCost)->toBe('0.000880')
        ->and($cost->communicationCost)->toBe('0.000000')
        ->and($cost->currency)->toBe('USD')
        ->and($cost->modelPriceId)->not->toBeNull()
        ->and($cost->aiModelId)->not->toBeNull()
        ->and($cost->snapshot['input_per_million'])->toBe('0.40000000')
        ->and($cost->isKnown())->toBeTrue();
});

it('bills cached tokens at the cached rate and subtracts them from the input', function () {
    pricedModel();

    // input 1000 of which 400 cached: 600 × 0.40/1M + 400 × 0.10/1M + 300 × 1.60/1M
    //  = 0.00024 + 0.00004 + 0.00048 = 0.00076
    $cost = app(CostCalculator::class)->calculate(costRecord(['cachedUnits' => 400]), CarbonImmutable::parse('2026-02-01 00:00:00'));

    expect($cost->providerCost)->toBe('0.000760');
});

it('bills cached tokens as input when the price has no cached rate, and adds per_request', function () {
    pricedModel(['cached_input_per_million' => null, 'per_request' => '0.00100000']);

    // 1000 × 0.40/1M + 300 × 1.60/1M + 0.001 = 0.00088 + 0.001 = 0.00188
    $cost = app(CostCalculator::class)->calculate(costRecord(['cachedUnits' => 400]), CarbonImmutable::parse('2026-02-01 00:00:00'));

    expect($cost->providerCost)->toBe('0.001880');
});

it('rounds half up to the ledger scale of 6 decimals', function () {
    // 1 token × 0.5/1M = 0.0000005 → 0.000001 (half up); 3 tokens × 0.15/1M = 0.00000045 → 0.000000
    pricedModel(['input_per_million' => '0.50000000', 'output_per_million' => '0.15000000', 'cached_input_per_million' => null]);

    $calc = app(CostCalculator::class);
    $at = CarbonImmutable::parse('2026-02-01 00:00:00');

    expect($calc->calculate(costRecord(['inputUnits' => 1, 'outputUnits' => 0]), $at)->providerCost)->toBe('0.000001')
        ->and($calc->calculate(costRecord(['inputUnits' => 0, 'outputUnits' => 3]), $at)->providerCost)->toBe('0.000000');
});

it('uses the price in force at occurred_at, not the current one', function () {
    $model = pricedModel(['effective_from' => CarbonImmutable::parse('2026-01-01 00:00:00'), 'effective_until' => CarbonImmutable::parse('2026-03-01 00:00:00')]);
    ModelPrice::factory()->for($model, 'model')->create([
        'input_per_million' => '4.00000000', 'output_per_million' => '16.00000000', 'cached_input_per_million' => null,
        'effective_from' => CarbonImmutable::parse('2026-03-01 00:00:00'),
    ]);

    $calc = app(CostCalculator::class);

    expect($calc->calculate(costRecord(), CarbonImmutable::parse('2026-02-15 00:00:00'))->providerCost)->toBe('0.000880')
        ->and($calc->calculate(costRecord(), CarbonImmutable::parse('2026-03-15 00:00:00'))->providerCost)->toBe('0.008800');
});

it('marks a row UNPRICED (none) when no price is in force — zero is an unknown cost, not free', function () {
    pricedModel(['effective_from' => CarbonImmutable::parse('2026-06-01 00:00:00')]);

    $cost = app(CostCalculator::class)->calculate(costRecord(), CarbonImmutable::parse('2026-02-01 00:00:00'));

    expect($cost->source)->toBe(CostSource::None)
        ->and($cost->isKnown())->toBeFalse()
        ->and($cost->providerCost)->toBe('0.000000')
        ->and($cost->modelPriceId)->toBeNull()
        ->and($cost->aiModelId)->not->toBeNull(); // the model was known, only the price was missing
});

it('marks a row UNPRICED (none) when the model is not in the catalog at all', function () {
    $cost = app(CostCalculator::class)->calculate(costRecord(['model' => 'mystery-model']), CarbonImmutable::now());

    expect($cost->source)->toBe(CostSource::None)
        ->and($cost->aiModelId)->toBeNull()
        ->and($cost->modelPriceId)->toBeNull();
});

it('marks a row currency_mismatch when the only price is in another currency', function () {
    pricedModel(['currency' => 'EUR']);

    $cost = app(CostCalculator::class)->calculate(costRecord(), CarbonImmutable::parse('2026-02-01 00:00:00'));

    expect($cost->source)->toBe(CostSource::CurrencyMismatch)
        ->and($cost->isKnown())->toBeFalse()
        ->and($cost->providerCost)->toBe('0.000000')
        ->and($cost->modelPriceId)->not->toBeNull() // the mismatching price is referenced for diagnosis
        ->and($cost->currency)->toBe('USD');
});

it('falls back to the legacy configurable rate (config_rate) when there is no DB price but a rate is set', function () {
    config(['billing.cost_rates.ai_reply' => ['unit' => 'event', 'rate' => 0.25]]);

    $cost = app(CostCalculator::class)->calculate(costRecord(['model' => 'unknown']), CarbonImmutable::now());

    expect($cost->source)->toBe(CostSource::ConfigRate)
        ->and($cost->providerCost)->toBe('0.250000')
        ->and($cost->isKnown())->toBeTrue();
});

it('prefers the DB price over the legacy rate when both exist', function () {
    pricedModel();
    config(['billing.cost_rates.ai_reply' => ['unit' => 'event', 'rate' => 0.25]]);

    $cost = app(CostCalculator::class)->calculate(costRecord(), CarbonImmutable::parse('2026-02-01 00:00:00'));

    expect($cost->source)->toBe(CostSource::ModelPrice)
        ->and($cost->providerCost)->toBe('0.000880');
});

it('keeps WhatsApp dimensions on the B1 communication-cost path', function () {
    config(['billing.cost_rates.whatsapp_outbound' => ['unit' => 'event', 'rate' => 0.005]]);

    $cost = app(CostCalculator::class)->calculate(costRecord(['dimension' => UsageDimension::WhatsAppOutbound, 'provider' => 'whatsapp', 'model' => null]), CarbonImmutable::now());

    expect($cost->source)->toBe(CostSource::ConfigRate)
        ->and($cost->communicationCost)->toBe('0.005000')
        ->and($cost->providerCost)->toBe('0.000000')
        ->and($cost->totalCost)->toBe('0.005000');
});
