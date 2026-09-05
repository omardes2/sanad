<?php

declare(strict_types=1);

use App\Contracts\Ai\CatalogSource;
use App\Data\Ai\Catalog\RoutingContext;
use App\Enums\AiOperation;
use App\Exceptions\Ai\AiConfigurationException;
use App\Services\Ai\Catalog\ConfigCatalogSource;
use App\Services\Ai\SanadAiRouter;

function routerConfigure(array $overrides = []): void
{
    aiConfigure(array_merge([
        'ai.catalog' => [],
        'ai.providers.openai.base_url' => 'https://api.openai.com/v1',
        'ai.providers.openai.api_key' => 'test-openai-key',
        'ai.providers.openai.model' => 'gpt-4.1-mini',
    ], $overrides));
}

it('derives a catalog from configured providers when none is declared', function () {
    routerConfigure();

    $specs = app(ConfigCatalogSource::class)->candidates(AiOperation::Chat, new RoutingContext);
    $keys = array_map(fn ($s) => $s->provider.':'.$s->model, $specs);

    // Preferred provider (groq) ranked first; providers without a model (gemini/ollama) skipped.
    expect($keys[0])->toBe('groq:llama-3.3-70b-versatile')
        ->and($keys)->toContain('openai:gpt-4.1-mini')
        ->and($keys)->not->toContain('gemini:')
        ->and($specs[0]->supports(AiOperation::Chat))->toBeTrue();
});

it('routes chat to the preferred provider when it is configured', function () {
    routerConfigure(['ai.provider' => 'openai']);

    $route = app(SanadAiRouter::class)->route(AiOperation::Chat);

    expect($route->provider->name())->toBe('openai')
        ->and($route->model)->toBe('gpt-4.1-mini')
        // Groq remains available as the next alternative.
        ->and($route->alternatives[0]->provider)->toBe('groq');
});

it('falls through to another configured provider when the preferred one has no credentials', function () {
    routerConfigure(['ai.provider' => 'openai', 'ai.providers.openai.api_key' => '']);

    $route = app(SanadAiRouter::class)->route(AiOperation::Chat);

    expect($route->provider->name())->toBe('groq');
});

it('honours an explicit catalog with priorities and disabled entries', function () {
    routerConfigure([
        'ai.provider' => 'groq',
        'ai.catalog' => [
            ['provider' => 'openai', 'model' => 'gpt-4.1', 'capabilities' => ['chat'], 'enabled' => true, 'priority' => 50],
            ['provider' => 'openai', 'model' => 'gpt-4.1-mini', 'capabilities' => ['chat'], 'enabled' => false, 'priority' => 90],
            ['provider' => 'groq', 'model' => 'llama-3.3-70b-versatile', 'capabilities' => ['chat'], 'priority' => 10],
        ],
    ]);

    // Preference for groq outranks priority: groq first, then the enabled OpenAI entry.
    $route = app(SanadAiRouter::class)->route(AiOperation::Chat);
    expect($route->provider->name())->toBe('groq');

    // With openai preferred: the disabled high-priority entry is skipped for the enabled one.
    config(['ai.provider' => 'openai']);
    $route = app(SanadAiRouter::class)->route(AiOperation::Chat);
    expect($route->provider->name())->toBe('openai')
        ->and($route->model)->toBe('gpt-4.1');
});

it('lets the context override the preferred provider', function () {
    routerConfigure(['ai.provider' => 'groq']);

    $route = app(SanadAiRouter::class)->route(AiOperation::Chat, new RoutingContext(preferredProvider: 'openai'));

    expect($route->provider->name())->toBe('openai');
});

it('throws a configuration error when no provider can serve the operation', function () {
    routerConfigure(['ai.providers.openai.api_key' => '', 'ai.providers.groq.api_key' => '']);

    expect(fn () => app(SanadAiRouter::class)->route(AiOperation::Chat))
        ->toThrow(AiConfigurationException::class);

    // No configured provider declares vision yet.
    routerConfigure();
    expect(fn () => app(SanadAiRouter::class)->route(AiOperation::Vision))
        ->toThrow(AiConfigurationException::class);
});

it('is bound to the config-backed catalog source by default', function () {
    expect(app(CatalogSource::class))->toBeInstanceOf(ConfigCatalogSource::class);
});
