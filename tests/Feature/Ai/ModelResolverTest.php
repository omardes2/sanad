<?php

declare(strict_types=1);

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Services\Ai\Catalog\ModelResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function resolverCatalog(): AiModel
{
    $openai = AiProvider::factory()->create(['key' => 'openai']);

    return AiModel::factory()->for($openai, 'provider')->create([
        'external_id' => 'gpt-4.1-mini',
        'aliases' => ['gpt-4.1-mini-2025-04-14', 'gpt-4.1-mini-latest'],
    ]);
}

it('resolves by exact external id', function () {
    $model = resolverCatalog();

    expect(app(ModelResolver::class)->resolve('openai', 'gpt-4.1-mini')?->id)->toBe($model->id);
});

it('resolves a dated snapshot id reported by the provider through aliases', function () {
    $model = resolverCatalog();

    expect(app(ModelResolver::class)->resolve('openai', 'gpt-4.1-mini-2025-04-14')?->id)->toBe($model->id)
        ->and(app(ModelResolver::class)->resolve('openai', 'gpt-4.1-mini-latest')?->id)->toBe($model->id);
});

it('falls back to the routed model when the reported id is unknown', function () {
    $model = resolverCatalog();

    expect(app(ModelResolver::class)->resolve('openai', 'gpt-4.1-mini-2099-01-01', 'gpt-4.1-mini')?->id)->toBe($model->id);
});

it('returns null for an unknown provider or model — never guesses', function () {
    resolverCatalog();

    expect(app(ModelResolver::class)->resolve('openai', 'gpt-5-ultra'))->toBeNull()
        ->and(app(ModelResolver::class)->resolve('groq', 'gpt-4.1-mini'))->toBeNull() // provider scoped
        ->and(app(ModelResolver::class)->resolve('openai', null, null))->toBeNull();
});

it('resolves disabled models too (cost is attributed to the model that actually served)', function () {
    $model = resolverCatalog();
    $model->forceFill(['is_enabled' => false])->save();

    expect(app(ModelResolver::class)->resolve('openai', 'gpt-4.1-mini')?->id)->toBe($model->id);
});
