<?php

declare(strict_types=1);

use App\Data\Ai\Catalog\RoutingContext;
use App\Enums\AiOperation;
use App\Exceptions\Settings\ReadOnlySettingException;
use App\Livewire\Dashboard\Ai\Routing as RoutingPage;
use App\Livewire\Dashboard\Settings as SettingsPage;
use App\Models\AuditLog;
use App\Services\Ai\AiManager;
use App\Services\Ai\Catalog\CatalogCache;
use App\Services\Ai\Catalog\CatalogSourceResolver;
use App\Services\Ai\Catalog\ConfigCatalogSource;
use App\Services\Ai\Catalog\RoutingSimulator;
use App\Services\Ai\Routing\RoutingPreference;
use App\Support\Audit\AuditActions;
use App\Support\Rbac\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(userWithRole(Role::SuperAdmin));
    $this->fx = c4Catalog();
});

it('env mode: AI_PROVIDER governs the router, the config catalog ranking and the manager default — exactly as before C4', function () {
    $pref = app(RoutingPreference::class);

    expect($pref->mode())->toBe('env')
        ->and($pref->resolve()->source)->toBe('env')
        ->and($pref->preferredProvider())->toBe('groq')
        ->and(app(RoutingSimulator::class)->current()->selectedHandle())->toBe('groq:llama-3.3-70b-versatile')
        ->and(app(AiManager::class)->provider()->name())->toBe('groq')
        ->and(app(ConfigCatalogSource::class)->candidates(AiOperation::Chat, new RoutingContext)[0]->provider)->toBe('groq');

    // is_primary is ignored in env mode.
    $this->fx['openai']->forceFill(['is_primary' => true])->save();
    CatalogCache::flush();
    expect(app(RoutingPreference::class)->preferredProvider())->toBe('groq');
});

it('db mode: the enabled is_primary provider governs; the database catalog then routes to it', function () {
    config(['ai.routing.mode' => 'db', 'ai.catalog_source' => 'database']);
    $this->fx['openai']->forceFill(['is_primary' => true])->save();
    CatalogCache::flush();

    $resolution = app(RoutingPreference::class)->resolve();

    expect($resolution->source)->toBe('db')
        ->and($resolution->provider)->toBe('openai')
        ->and($resolution->primaryProviderId)->toBe($this->fx['openai']->id)
        ->and(app(RoutingSimulator::class)->current()->selectedHandle())->toBe('openai:gpt-4.1-mini')
        ->and(app(AiManager::class)->provider()->name())->toBe('openai');
});

it('db mode without a usable primary falls back to AI_PROVIDER in a DEGRADED state: warning, rate-limited system audit, banner, stored mode untouched', function () {
    config(['ai.routing.mode' => 'db', 'ai.catalog_source' => 'database']);
    CatalogCache::flush();
    Log::spy();

    $resolution = app(RoutingPreference::class)->resolve();
    app(RoutingPreference::class)->resolve();

    expect($resolution->degraded())->toBeTrue()
        ->and($resolution->source)->toBe('env_fallback')
        ->and($resolution->provider)->toBe('groq')
        ->and($resolution->degradedReason)->toBe('no_primary')
        ->and(app(RoutingSimulator::class)->current()->selectedHandle())->toBe('groq:llama-3.3-70b-versatile')
        ->and(AuditLog::where('action', AuditActions::AiRoutingEnvFallbackUsed)->count())->toBe(1)
        ->and(app(RoutingPreference::class)->mode())->toBe('db'); // never changed automatically
    Log::shouldHaveReceived('warning')->with('sanad.routing.env_fallback', Mockery::any())->atLeast()->once();

    // A disabled primary is the same emergency.
    $this->fx['openai']->forceFill(['is_primary' => true, 'is_enabled' => false])->save();
    CatalogCache::flush();
    expect(app(RoutingPreference::class)->resolve()->degradedReason)->toBe('primary_disabled');

    Livewire::actingAs(userWithRole(Role::Operations))->test(RoutingPage::class)->assertSee('DEGRADED / ENV FALLBACK');
});

it('AI_ROUTING_MODE in the environment overrides the stored mode (emergency rollback)', function () {
    config(['ai.routing.mode' => 'db', 'ai.overrides.routing_mode' => 'env']);
    expect(app(RoutingPreference::class)->mode())->toBe('env')
        ->and(settings()->effective('ai.routing.mode')->envForced())->toBeTrue();
});

it('the two managed settings cannot be written through the generic settings path or page — only the cutover service', function () {
    expect(fn () => settings()->set('ai.routing.mode', 'db'))->toThrow(ReadOnlySettingException::class)
        ->and(fn () => settings()->set('ai.catalog_source', 'database'))->toThrow(ReadOnlySettingException::class)
        ->and(fn () => settings()->reset('ai.catalog_source'))->toThrow(ReadOnlySettingException::class);

    Livewire::actingAs(userWithRole(Role::SuperAdmin))
        ->test(SettingsPage::class)
        ->assertSee('يُغيَّر من صفحة Cutover فقط')
        ->assertDontSee('values.ai__routing__mode')
        ->set('values.ai__catalog_source', 'database')
        ->call('save', 'ai.catalog_source')
        ->assertSee('للعرض فقط');

    expect(app(CatalogSourceResolver::class)->mode())->toBe('config');
});
