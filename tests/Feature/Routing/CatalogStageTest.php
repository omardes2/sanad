<?php

declare(strict_types=1);

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Services\Ai\Catalog\CatalogCache;
use App\Services\Ai\Catalog\CatalogSourceResolver;
use App\Services\Ai\Catalog\RoutingSimulator;
use App\Services\Ai\Routing\RoutingCutover;
use App\Support\Rbac\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(userWithRole(Role::SuperAdmin));
    aiConfigure([
        'ai.providers.openai.base_url' => 'https://api.openai.com/v1',
        'ai.providers.openai.api_key' => 'test-openai-key',
        'ai.providers.openai.model' => 'gpt-4.1-mini',
        'ai.overrides.catalog_source' => null,
    ]);
});

it('Stage A: bootstrap --apply is refused while the source is `auto` (it would flip the runtime), and with `config` it populates the catalog WITHOUT the runtime using it', function () {
    config(['ai.catalog_source' => 'auto']);

    $this->artisan('sanad:ai:bootstrap', ['--apply' => true])->expectsOutputToContain('Refusing --apply')->assertFailed();
    expect(AiProvider::count())->toBe(0)->and(AiModel::count())->toBe(0);

    config(['ai.catalog_source' => 'config']);
    $routeBefore = app(RoutingSimulator::class)->current()->selectedHandle();

    $this->artisan('sanad:ai:bootstrap')->expectsOutputToContain('Dry run')->assertSuccessful();
    expect(AiProvider::count())->toBe(0);

    $this->artisan('sanad:ai:bootstrap', ['--apply' => true])->expectsOutputToContain('Effective catalog source: config')->assertSuccessful();
    CatalogCache::flush();

    expect(AiProvider::count())->toBe(2)->and(AiModel::count())->toBe(2)
        ->and(app(CatalogSourceResolver::class)->mode())->toBe('config')
        ->and(app(CatalogSourceResolver::class)->activeName())->toBe('config') // runtime still config
        ->and(app(RoutingSimulator::class)->current()->selectedHandle())->toBe($routeBefore)
        ->and(AiProvider::where('is_primary', true)->count())->toBe(0);
});

it('Stage B: the database-catalog what-if shows the route the DB would produce, exclusions, readiness and COST UNKNOWN, while the runtime stays on config and nothing is written', function () {
    config(['ai.catalog_source' => 'config']);
    // A database catalog that DIFFERS from config: only openai, at a high priority.
    $openai = AiProvider::factory()->create(['key' => 'openai', 'driver' => 'openai', 'priority' => 500]);
    $gemini = AiProvider::factory()->create(['key' => 'gemini', 'driver' => 'gemini', 'priority' => 900]); // no adapter
    AiModel::factory()->for($openai, 'provider')->create(['external_id' => 'gpt-4.1-mini']);
    AiModel::factory()->for($gemini, 'provider')->create(['external_id' => 'gemini-pro']);
    CatalogCache::flush();

    $preview = app(RoutingCutover::class)->whatIfDatabaseCatalog();
    $reasons = collect($preview->after->candidates)->mapWithKeys(fn (array $r) => [$r['spec']->provider.':'.$r['spec']->model => $r['reason']]);

    expect($preview->before->selectedHandle())->toBe('groq:llama-3.3-70b-versatile')
        ->and($preview->after->selectedHandle())->toBe('openai:gpt-4.1-mini')
        ->and($reasons['gemini:gemini-pro'])->toBe('unknown_provider')
        ->and($preview->sameRoute())->toBeFalse()
        ->and($preview->readiness?->provider)->toBe('openai')
        ->and(implode(' ', $preview->warnings))->toContain('COST UNKNOWN')
        ->and(app(CatalogSourceResolver::class)->activeName())->toBe('config')
        ->and(app(RoutingSimulator::class)->current()->selectedHandle())->toBe('groq:llama-3.3-70b-versatile')
        ->and(AuditLog::where('action', 'like', 'ai.%')->count())->toBe(0)
        ->and(AppSetting::count())->toBe(0);
});
