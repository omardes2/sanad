<?php

declare(strict_types=1);

use App\Exceptions\Ai\CatalogValidationException;
use App\Exceptions\Ai\FallbackCycleException;
use App\Exceptions\Ai\LastViableRouteException;
use App\Exceptions\Ai\RoutingChangeConfirmationRequired;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\AuditLog;
use App\Models\ModelPrice;
use App\Models\UsageEvent;
use App\Services\Ai\Catalog\CatalogAdmin;
use App\Services\Ai\Catalog\CatalogCache;
use App\Services\Ai\Catalog\DatabaseCatalogSource;
use App\Services\Ai\Catalog\RoutingSimulator;
use App\Services\Audit\AuditLogger;
use App\Support\Audit\AuditActions;
use App\Support\Rbac\Role;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

/**
 * Two configured providers (groq preferred via AI_PROVIDER, openai second) in
 * the DATABASE catalog, so routing decisions come from the rows under test.
 */
function catalogFixture(): array
{
    aiConfigure([
        'ai.providers.openai.base_url' => 'https://api.openai.com/v1',
        'ai.providers.openai.api_key' => 'test-openai-key',
        'ai.providers.openai.model' => 'gpt-4.1-mini',
    ]);
    settings()->set('ai.catalog_source', 'database');

    $groq = AiProvider::factory()->create(['key' => 'groq', 'driver' => 'groq', 'priority' => 100]);
    $openai = AiProvider::factory()->create(['key' => 'openai', 'driver' => 'openai', 'priority' => 10]);
    $llama = AiModel::factory()->for($groq, 'provider')->create(['external_id' => 'llama-3.3-70b-versatile', 'priority' => 5]);
    $mini = AiModel::factory()->for($openai, 'provider')->create(['external_id' => 'gpt-4.1-mini']);

    CatalogCache::flush();

    return compact('groq', 'openai', 'llama', 'mini');
}

function modelInput(array $overrides = []): array
{
    return array_merge([
        'external_id' => 'new-model', 'name' => 'New', 'aliases' => [], 'capabilities' => ['chat'],
        'supports_tools' => true, 'context_window' => null, 'max_output_tokens' => null,
        'priority' => 0, 'is_enabled' => false, 'fallback_model_id' => null,
    ], $overrides);
}

function providerInput(AiProvider $provider, array $overrides = []): array
{
    return array_merge([
        'name' => $provider->name, 'base_url' => $provider->base_url, 'priority' => $provider->priority,
        'is_enabled' => $provider->is_enabled, 'capabilities' => $provider->capabilities ?? ['chat'],
    ], $overrides);
}

beforeEach(function () {
    $this->fx = catalogFixture();
    $this->actingAs(userWithRole(Role::SuperAdmin));
    $this->admin = app(CatalogAdmin::class);
});

// ---- Authorization -----------------------------------------------------------

it('enforces the permission server-side regardless of the caller', function () {
    $this->actingAs(userWithRole(Role::Finance));

    expect(fn () => $this->admin->updateProvider($this->fx['groq'], providerInput($this->fx['groq'], ['name' => 'X'])))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $this->admin->createModel(modelInput() + ['provider_id' => $this->fx['groq']->id]))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $this->admin->updateModel($this->fx['mini'], modelInput(['external_id' => 'gpt-4.1-mini'])))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $this->admin->deleteModel($this->fx['mini']))
        ->toThrow(AuthorizationException::class);

    expect(AuditLog::where('action', 'like', 'ai.%')->count())->toBe(0);
});

// ---- Uniqueness ----------------------------------------------------------------

it('rejects an external_id or alias already used by a sibling model, in either direction', function () {
    $groq = $this->fx['groq'];

    $this->admin->updateModel($this->fx['llama'], modelInput(['external_id' => 'llama-3.3-70b-versatile', 'aliases' => ['llama-70b', 'llama-latest'], 'is_enabled' => true]));

    expect(fn () => $this->admin->createModel(modelInput(['provider_id' => $groq->id, 'external_id' => 'llama-70b'])))
        ->toThrow(CatalogValidationException::class, 'llama-70b')
        ->and(fn () => $this->admin->createModel(modelInput(['provider_id' => $groq->id, 'external_id' => 'other', 'aliases' => ['llama-3.3-70b-versatile']])))
        ->toThrow(CatalogValidationException::class, 'llama-3.3-70b-versatile')
        ->and(fn () => $this->admin->createModel(modelInput(['provider_id' => $groq->id, 'external_id' => 'other', 'aliases' => ['llama-latest']])))
        ->toThrow(CatalogValidationException::class, 'llama-latest')
        ->and(fn () => $this->admin->createModel(modelInput(['provider_id' => $groq->id, 'external_id' => 'other', 'aliases' => ['other']])))
        ->toThrow(CatalogValidationException::class, 'فريدة');

    // The same ids are free under ANOTHER provider (uniqueness is per provider).
    $created = $this->admin->createModel(modelInput(['provider_id' => $this->fx['openai']->id, 'external_id' => 'llama-70b', 'aliases' => ['llama-latest']]));

    expect($created->exists)->toBeTrue()
        ->and(AiModel::where('provider_id', $groq->id)->count())->toBe(1);
});

it('accepts aliases as a comma/space separated string and validates identifiers', function () {
    $model = $this->admin->createModel(modelInput(['provider_id' => $this->fx['openai']->id, 'external_id' => 'gpt-4.1', 'aliases' => 'gpt-4.1-2025-04-14, gpt-4.1-latest']));

    expect($model->aliases)->toBe(['gpt-4.1-2025-04-14', 'gpt-4.1-latest'])
        ->and(fn () => $this->admin->createModel(modelInput(['provider_id' => $this->fx['openai']->id, 'external_id' => 'bad id with spaces'])))
        ->toThrow(CatalogValidationException::class);
});

// ---- Fallback graph --------------------------------------------------------------

it('rejects self-reference, cycles and over-deep fallback chains, and accepts a valid cross-provider fallback', function () {
    ['llama' => $llama, 'mini' => $mini, 'openai' => $openai] = $this->fx;

    expect(fn () => $this->admin->updateModel($llama, modelInput(['external_id' => $llama->external_id, 'is_enabled' => true, 'fallback_model_id' => $llama->id])))
        ->toThrow(FallbackCycleException::class);

    // llama → mini is fine (cross-provider).
    $this->admin->updateModel($llama, modelInput(['external_id' => $llama->external_id, 'priority' => 5, 'is_enabled' => true, 'fallback_model_id' => $mini->id]));
    expect($llama->fresh()->fallback_model_id)->toBe($mini->id);

    // mini → llama would close the loop.
    expect(fn () => $this->admin->updateModel($mini, modelInput(['external_id' => $mini->external_id, 'is_enabled' => true, 'fallback_model_id' => $llama->id])))
        ->toThrow(FallbackCycleException::class, 'حلقة');

    // A chain deeper than MAX_DEPTH is refused.
    $chain = [];
    $previous = null;
    for ($i = 0; $i < 6; $i++) {
        $chain[$i] = AiModel::factory()->for($openai, 'provider')->create(['external_id' => "chain-{$i}", 'is_enabled' => false, 'fallback_model_id' => $previous]);
        $previous = $chain[$i]->id;
    }
    $head = AiModel::factory()->for($openai, 'provider')->create(['external_id' => 'chain-head', 'is_enabled' => false]);

    expect(fn () => $this->admin->updateModel($head, modelInput(['external_id' => 'chain-head', 'fallback_model_id' => $chain[5]->id])))
        ->toThrow(FallbackCycleException::class, 'أطول')
        ->and($head->fresh()->fallback_model_id)->toBeNull()
        ->and(fn () => $this->admin->updateModel($head, modelInput(['external_id' => 'chain-head', 'fallback_model_id' => 999999])))
        ->toThrow(CatalogValidationException::class);
});

// ---- Last viable route + simulation ------------------------------------------------

it('blocks disabling the last viable chat route (model, then provider) server-side', function () {
    ['groq' => $groq, 'openai' => $openai, 'llama' => $llama, 'mini' => $mini] = $this->fx;

    // Disable openai's model with confirmation not needed (groq stays selected).
    $this->admin->updateModel($mini, modelInput(['external_id' => $mini->external_id, 'is_enabled' => false]));

    expect(fn () => $this->admin->updateModel($llama, modelInput(['external_id' => $llama->external_id, 'priority' => 5, 'is_enabled' => false])))
        ->toThrow(LastViableRouteException::class)
        ->and($llama->fresh()->is_enabled)->toBeTrue()
        ->and(fn () => $this->admin->updateProvider($groq, providerInput($groq, ['is_enabled' => false])))
        ->toThrow(LastViableRouteException::class)
        ->and($groq->fresh()->is_enabled)->toBeTrue()
        ->and(AuditLog::where('action', AuditActions::AiProviderUpdated)->count())->toBe(0);
});

it('requires a typed confirmation equal to the NEW route when the selected chat route would change, and audits the simulation', function () {
    ['groq' => $groq, 'llama' => $llama] = $this->fx;

    // Disabling groq's only model moves chat to openai:gpt-4.1-mini.
    expect(fn () => $this->admin->updateModel($llama, modelInput(['external_id' => $llama->external_id, 'priority' => 5, 'is_enabled' => false])))
        ->toThrow(RoutingChangeConfirmationRequired::class)
        ->and(fn () => $this->admin->updateModel($llama, modelInput(['external_id' => $llama->external_id, 'priority' => 5, 'is_enabled' => false]), 'wrong'))
        ->toThrow(RoutingChangeConfirmationRequired::class)
        ->and($llama->fresh()->is_enabled)->toBeTrue();

    try {
        $this->admin->updateModel($llama, modelInput(['external_id' => $llama->external_id, 'priority' => 5, 'is_enabled' => false]));
    } catch (RoutingChangeConfirmationRequired $e) {
        expect($e->before)->toBe('groq:llama-3.3-70b-versatile')
            ->and($e->after)->toBe('openai:gpt-4.1-mini')
            ->and($e->expectedConfirmation())->toBe('openai:gpt-4.1-mini');
    }

    $this->admin->updateModel($llama, modelInput(['external_id' => $llama->external_id, 'priority' => 5, 'is_enabled' => false]), 'openai:gpt-4.1-mini');

    $log = AuditLog::where('action', AuditActions::AiModelUpdated)->latest('id')->firstOrFail();

    expect($llama->fresh()->is_enabled)->toBeFalse()
        ->and($log->changes()['is_enabled'])->toBe(['from' => true, 'to' => false])
        ->and($log->context()['simulation'])->toMatchArray(['before' => 'groq:llama-3.3-70b-versatile', 'after' => 'openai:gpt-4.1-mini', 'confirmed' => true]);

    // Disabling the provider itself: no route changes now (groq has no enabled model) → no confirmation needed.
    $this->admin->updateProvider($groq, providerInput($groq, ['is_enabled' => false]));
    expect($groq->fresh()->is_enabled)->toBeFalse();
});

it('simulates enabling too: in auto mode the first enabled database model of an unconfigured provider is refused', function () {
    settings()->set('ai.catalog_source', 'auto');
    AiModel::query()->update(['is_enabled' => false]);
    CatalogCache::flush();

    $ghost = AiProvider::factory()->create(['key' => 'gemini', 'driver' => 'gemini', 'priority' => 500]);
    $model = AiModel::factory()->for($ghost, 'provider')->create(['external_id' => 'gemini-pro', 'is_enabled' => false]);

    // Config catalog routes groq today; enabling gemini-pro would switch the auto source to a database with no configured route.
    expect(fn () => $this->admin->updateModel($model, modelInput(['external_id' => 'gemini-pro', 'is_enabled' => true])))
        ->toThrow(LastViableRouteException::class)
        ->and($model->fresh()->is_enabled)->toBeFalse();

    // Enabling groq's model instead keeps the same selected route → allowed without confirmation.
    $this->admin->updateModel($this->fx['llama'], modelInput(['external_id' => 'llama-3.3-70b-versatile', 'priority' => 5, 'is_enabled' => true]));
    expect($this->fx['llama']->fresh()->is_enabled)->toBeTrue();
});

// ---- Atomicity + cache ------------------------------------------------------------------

it('writes the change and its audit entry atomically: an audit failure leaves the row untouched', function () {
    $llama = $this->fx['llama'];

    $audit = Mockery::mock(AuditLogger::class);
    $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('audit store down'));
    $admin = new CatalogAdmin($audit, app(RoutingSimulator::class));

    expect(fn () => $admin->updateModel($llama, modelInput(['external_id' => $llama->external_id, 'name' => 'Renamed', 'priority' => 5, 'is_enabled' => true])))
        ->toThrow(RuntimeException::class)
        ->and($llama->fresh()->name)->not->toBe('Renamed')
        ->and(AuditLog::where('action', AuditActions::AiModelUpdated)->count())->toBe(0);
});

it('invalidates the catalog cache after the commit, and tolerates a failing cache store', function () {
    $llama = $this->fx['llama'];
    $source = app(DatabaseCatalogSource::class);

    // Warm the cache.
    expect(collect($source->allSpecs())->pluck('model')->all())->toContain('llama-3.3-70b-versatile');

    $this->admin->updateModel($llama, modelInput(['external_id' => 'llama-3.3-70b-renamed', 'priority' => 5, 'is_enabled' => true]));

    expect(collect($source->allSpecs())->pluck('model')->all())->toContain('llama-3.3-70b-renamed')
        ->and(AuditLog::where('action', AuditActions::AiModelUpdated)->count())->toBe(1);

    // Cache store failure: the write still succeeds, a warning is logged.
    Log::spy();
    Cache::partialMock()->shouldReceive('forever')->andThrow(new RuntimeException('redis down'));

    $this->admin->updateModel($llama->fresh(), modelInput(['external_id' => 'llama-3.3-70b-renamed', 'name' => 'Llama', 'priority' => 5, 'is_enabled' => true]));

    expect($llama->fresh()->name)->toBe('Llama')
        ->and(AuditLog::where('action', AuditActions::AiModelUpdated)->count())->toBe(2);
    Log::shouldHaveReceived('warning')->with('sanad.ai.catalog_cache_unavailable', Mockery::any())->atLeast()->once();
});

it('records no audit entry when nothing changed', function () {
    $mini = $this->fx['mini'];

    $this->admin->updateModel($mini, modelInput(['external_id' => $mini->external_id, 'name' => $mini->name, 'is_enabled' => true]));

    expect(AuditLog::where('action', AuditActions::AiModelUpdated)->count())->toBe(0);
});

// ---- Providers ---------------------------------------------------------------------------

it('validates base_url with the SSRF policy, stores it only, and never accepts locked fields', function () {
    $openai = $this->fx['openai'];

    foreach (['http://api.example.com', 'https://localhost/v1', 'https://10.0.0.5/v1', 'https://169.254.169.254/latest', 'https://metadata.google.internal/', 'https://user:pw@api.example.com/', 'https://[::1]/', 'https://100.64.1.1/', 'https://api.internal/', 'not a url'] as $bad) {
        expect(fn () => $this->admin->updateProvider($openai, providerInput($openai, ['base_url' => $bad])))
            ->toThrow(CatalogValidationException::class, '', "should reject {$bad}");
    }

    $updated = $this->admin->updateProvider($openai, providerInput($openai, ['base_url' => 'https://proxy.example.com/v1']));
    $log = AuditLog::where('action', AuditActions::AiProviderUpdated)->latest('id')->firstOrFail();

    expect($updated->base_url)->toBe('https://proxy.example.com/v1')
        ->and($log->context()['base_url_applied'])->toBeFalse()
        ->and(config('ai.providers.openai.base_url'))->toBe('https://api.openai.com/v1');

    foreach (['key' => 'x', 'driver' => 'x', 'credentials_ref' => 'X', 'is_primary' => true] as $field => $value) {
        expect(fn () => $this->admin->updateProvider($openai, providerInput($openai) + [$field => $value]))
            ->toThrow(CatalogValidationException::class, $field);
    }

    expect($openai->fresh()->is_primary)->toBeFalse();
});

// ---- Deletion protection -----------------------------------------------------------------

it('deletes only a disabled, unreferenced model', function () {
    ['openai' => $openai, 'mini' => $mini, 'llama' => $llama] = $this->fx;

    expect(fn () => $this->admin->deleteModel($mini))->toThrow(CatalogValidationException::class, 'مفعّل');

    $this->admin->updateModel($mini, modelInput(['external_id' => $mini->external_id, 'is_enabled' => false]));

    ModelPrice::factory()->for($mini, 'model')->create();
    expect(fn () => $this->admin->deleteModel($mini))->toThrow(CatalogValidationException::class, 'أسعار');
    ModelPrice::where('model_id', $mini->id)->delete();

    $llama->update(['fallback_model_id' => $mini->id]);
    expect(fn () => $this->admin->deleteModel($mini))->toThrow(CatalogValidationException::class, 'بديل');
    $llama->update(['fallback_model_id' => null]);

    UsageEvent::factory()->create(['ai_model_id' => $mini->id]);
    expect(fn () => $this->admin->deleteModel($mini))->toThrow(CatalogValidationException::class, 'استخدام');
    UsageEvent::query()->delete();

    $this->admin->deleteModel($mini);

    expect(AiModel::find($mini->id))->toBeNull()
        ->and(AuditLog::where('action', AuditActions::AiModelDeleted)->count())->toBe(1);
});
