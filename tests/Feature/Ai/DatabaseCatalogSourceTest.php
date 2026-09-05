<?php

declare(strict_types=1);

use App\Contracts\Ai\CatalogSource;
use App\Data\Ai\Catalog\RoutingContext;
use App\Enums\AiOperation;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Services\Ai\Catalog\CatalogSourceResolver;
use App\Services\Ai\Catalog\ConfigCatalogSource;
use App\Services\Ai\Catalog\DatabaseCatalogSource;
use App\Services\Ai\SanadAiRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function catalogConfigure(array $overrides = []): void
{
    aiConfigure(array_merge([
        'ai.catalog' => [],
        'ai.catalog_source' => 'auto',
        'ai.providers.openai.base_url' => 'https://api.openai.com/v1',
        'ai.providers.openai.api_key' => 'test-openai-key',
        'ai.providers.openai.model' => 'gpt-4.1-mini',
    ], $overrides));
}

function registerCatalog(): array
{
    $openai = AiProvider::factory()->create(['key' => 'openai', 'priority' => 100]);
    $groq = AiProvider::factory()->create(['key' => 'groq', 'priority' => 10]);

    $mini = AiModel::factory()->for($openai, 'provider')->create(['external_id' => 'gpt-4.1-mini', 'priority' => 5]);
    $full = AiModel::factory()->for($openai, 'provider')->create(['external_id' => 'gpt-4.1', 'priority' => 9]);
    $llama = AiModel::factory()->for($groq, 'provider')->create(['external_id' => 'llama-3.3-70b-versatile']);

    return compact('openai', 'groq', 'mini', 'full', 'llama');
}

it('lists enabled models of enabled providers ordered by provider then model priority, with ids', function () {
    catalogConfigure();
    ['openai' => $openai, 'mini' => $mini, 'full' => $full] = registerCatalog();
    AiModel::factory()->for($openai, 'provider')->create(['external_id' => 'disabled-model', 'is_enabled' => false]);
    $off = AiProvider::factory()->create(['key' => 'off', 'is_enabled' => false, 'priority' => 999]);
    AiModel::factory()->for($off, 'provider')->create(['external_id' => 'ghost']);

    $specs = app(DatabaseCatalogSource::class)->candidates(AiOperation::Chat, new RoutingContext);
    $handles = array_map(fn ($s) => $s->provider.':'.$s->model, $specs);

    expect($handles)->toBe(['openai:gpt-4.1', 'openai:gpt-4.1-mini', 'groq:llama-3.3-70b-versatile'])
        ->and($specs[0]->modelId)->toBe($full->id)
        ->and($specs[1]->modelId)->toBe($mini->id)
        ->and($specs[0]->providerId)->toBe($openai->id)
        ->and($specs[0]->priority)->toBe(100);
});

it('filters by capability and exposes the fallback relation (cross-provider)', function () {
    catalogConfigure();
    ['mini' => $mini, 'llama' => $llama] = registerCatalog();
    $mini->forceFill(['capabilities' => ['chat', 'vision'], 'fallback_model_id' => $llama->id])->save();

    $source = app(DatabaseCatalogSource::class);
    $vision = $source->candidates(AiOperation::Vision, new RoutingContext);
    $chat = $source->candidates(AiOperation::Chat, new RoutingContext);
    $miniSpec = collect($chat)->first(fn ($s) => $s->model === 'gpt-4.1-mini');

    expect(array_map(fn ($s) => $s->model, $vision))->toBe(['gpt-4.1-mini'])
        ->and($miniSpec->fallbackModel)->toBe('llama-3.3-70b-versatile')
        ->and($miniSpec->fallbackProvider)->toBe('groq');
});

it('the router places the declared fallback first among the alternatives', function () {
    catalogConfigure(['ai.provider' => 'openai']);
    ['full' => $full, 'llama' => $llama] = registerCatalog();
    $full->forceFill(['fallback_model_id' => $llama->id])->save();

    $route = app(SanadAiRouter::class)->route(AiOperation::Chat);

    expect($route->model)->toBe('gpt-4.1')
        ->and($route->alternatives[0]->provider.':'.$route->alternatives[0]->model)->toBe('groq:llama-3.3-70b-versatile')
        ->and($route->alternatives[1]->model)->toBe('gpt-4.1-mini');
});

it('resolver: auto mode serves the database catalog once it has an enabled model, and the config catalog otherwise', function () {
    catalogConfigure();
    $resolver = app(CatalogSourceResolver::class);

    expect($resolver->activeName())->toBe('config');

    registerCatalog();

    expect(app(CatalogSourceResolver::class)->activeName())->toBe('database')
        ->and(app(CatalogSource::class))->toBeInstanceOf(CatalogSourceResolver::class);
});

it('resolver: explicit modes are honoured (config = instant rollback switch)', function () {
    catalogConfigure();
    registerCatalog();

    config(['ai.catalog_source' => 'config']);
    expect(app(CatalogSourceResolver::class)->activeName())->toBe('config');

    config(['ai.catalog_source' => 'database']);
    expect(app(CatalogSourceResolver::class)->activeName())->toBe('database');

    config(['ai.catalog_source' => 'nonsense']);
    expect(app(CatalogSourceResolver::class)->mode())->toBe('auto');
});

it('with empty B2 tables the router routes exactly as the config catalog did (Groq first, unchanged)', function () {
    catalogConfigure(['ai.provider' => 'groq']);

    $viaResolver = app(CatalogSource::class)->candidates(AiOperation::Chat, new RoutingContext);
    $viaConfig = app(ConfigCatalogSource::class)->candidates(AiOperation::Chat, new RoutingContext);
    $route = app(SanadAiRouter::class)->route(AiOperation::Chat);

    expect($viaResolver)->toEqual($viaConfig)
        ->and($route->provider->name())->toBe('groq')
        ->and($route->model)->toBe('llama-3.3-70b-versatile');
});

it('AI_PROVIDER stays the operational preference: a DB primary provider does not override it in B2', function () {
    catalogConfigure(['ai.provider' => 'groq']);
    ['openai' => $openai] = registerCatalog();
    $openai->forceFill(['is_primary' => true])->save();

    $route = app(SanadAiRouter::class)->route(AiOperation::Chat);

    expect(app(CatalogSourceResolver::class)->activeName())->toBe('database')
        ->and($route->provider->name())->toBe('groq')
        ->and($route->model)->toBe('llama-3.3-70b-versatile');
});

it('a DB provider without credentials in the environment is skipped, never called', function () {
    catalogConfigure(['ai.provider' => 'openai', 'ai.providers.openai.api_key' => '']);
    registerCatalog();

    $route = app(SanadAiRouter::class)->route(AiOperation::Chat);

    expect($route->provider->name())->toBe('groq');
});

it('a DB provider unknown to the AiManager is skipped', function () {
    catalogConfigure();
    registerCatalog();
    $mystery = AiProvider::factory()->create(['key' => 'mystery', 'priority' => 500]);
    AiModel::factory()->for($mystery, 'provider')->create(['external_id' => 'm1']);

    $route = app(SanadAiRouter::class)->route(AiOperation::Chat);

    expect($route->provider->name())->toBe('groq');
});

it('invalidates the catalog cache when a provider or model changes', function () {
    catalogConfigure(['ai.provider' => 'openai']);
    ['full' => $full] = registerCatalog();

    expect(app(SanadAiRouter::class)->route(AiOperation::Chat)->model)->toBe('gpt-4.1');

    $full->forceFill(['is_enabled' => false])->save();

    expect(app(SanadAiRouter::class)->route(AiOperation::Chat)->model)->toBe('gpt-4.1-mini');
});
