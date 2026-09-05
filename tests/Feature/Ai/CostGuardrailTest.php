<?php

declare(strict_types=1);

use App\Data\Ai\Catalog\RoutingContext;
use App\Enums\AiOperation;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\ModelPrice;
use App\Services\Ai\SanadAiRouter;
use App\Services\Billing\Pricing\CostEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function guardrailCatalog(): array
{
    aiConfigure([
        'ai.catalog' => [],
        'ai.provider' => 'openai',
        'ai.providers.openai.base_url' => 'https://api.openai.com/v1',
        'ai.providers.openai.api_key' => 'test-openai-key',
        'ai.providers.openai.model' => 'gpt-4.1',
    ]);

    $openai = AiProvider::factory()->create(['key' => 'openai', 'priority' => 100]);
    $groq = AiProvider::factory()->create(['key' => 'groq', 'priority' => 10]);
    $expensive = AiModel::factory()->for($openai, 'provider')->create(['external_id' => 'gpt-4.1', 'priority' => 9]);
    $cheap = AiModel::factory()->for($openai, 'provider')->create(['external_id' => 'gpt-4.1-mini', 'priority' => 5]);
    $llama = AiModel::factory()->for($groq, 'provider')->create(['external_id' => 'llama-3.3-70b-versatile']);

    // 1000 in × 2/1M + 300 out × 8/1M = 0.002 + 0.0024 = 0.0044
    ModelPrice::factory()->for($expensive, 'model')->create(['input_per_million' => '2.00000000', 'output_per_million' => '8.00000000']);
    // 1000 × 0.4/1M + 300 × 1.6/1M = 0.00088
    ModelPrice::factory()->for($cheap, 'model')->create(['input_per_million' => '0.40000000', 'output_per_million' => '1.60000000']);

    return compact('expensive', 'cheap', 'llama');
}

it('estimates the cost of one typical request from the current price, or null when unknown', function () {
    guardrailCatalog();
    $estimator = app(CostEstimator::class);
    $specs = app(SanadAiRouter::class)->route(AiOperation::Chat);

    $byModel = collect([$specs->spec, ...$specs->alternatives])->keyBy('model');

    expect($estimator->estimate($byModel['gpt-4.1']))->toBe(0.0044)
        ->and($estimator->estimate($byModel['gpt-4.1-mini']))->toBe(0.00088)
        ->and($estimator->estimate($byModel['llama-3.3-70b-versatile']))->toBeNull(); // no price → unknown
});

it('without a guardrail the most preferred model is chosen regardless of cost', function () {
    guardrailCatalog();

    expect(app(SanadAiRouter::class)->route(AiOperation::Chat)->model)->toBe('gpt-4.1');
});

it('with maxUnitCost the router skips a model whose KNOWN estimate exceeds it, but never one with an unknown cost', function () {
    guardrailCatalog();

    $route = app(SanadAiRouter::class)->route(AiOperation::Chat, new RoutingContext(maxUnitCost: 0.001));
    expect($route->model)->toBe('gpt-4.1-mini');

    // Below every known estimate: OpenAI models are skipped; Groq's cost is unknown → still eligible.
    $route = app(SanadAiRouter::class)->route(AiOperation::Chat, new RoutingContext(maxUnitCost: 0.0001));
    expect($route->provider->name())->toBe('groq');
});
