<?php

declare(strict_types=1);

use App\Enums\CredentialStatus;
use App\Exceptions\Ai\CatalogValidationException;
use App\Exceptions\Routing\CutoverBlockedException;
use App\Exceptions\Routing\StaleCutoverException;
use App\Models\AiProvider;
use App\Models\AuditLog;
use App\Models\ProviderHealthCheck;
use App\Services\Ai\Catalog\CatalogAdmin;
use App\Services\Ai\Catalog\CatalogCache;
use App\Services\Ai\Catalog\RoutingSimulator;
use App\Services\Ai\Routing\RoutingCutover;
use App\Services\Ai\Routing\RoutingPreference;
use App\Services\Credentials\CredentialManager;
use App\Support\Audit\AuditActions;
use App\Support\Rbac\Role;
use App\Support\Security\SecretString;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(userWithRole(Role::SuperAdmin));
    $this->fx = c4Catalog();
    $this->cutover = app(RoutingCutover::class);
    // Stage C already done for these tests: the database catalog governs.
    config(['ai.catalog_source' => 'database']);
    CatalogCache::flush();
});

it('env → db needs a usable primary and the database catalog, must keep the same route, needs the resulting provider:model, and is audited; primary set to Groq first keeps the route', function () {
    ['groq' => $groq, 'openai' => $openai] = $this->fx;

    // No primary yet.
    expect(implode(' ', $this->cutover->previewRoutingMode('db')->blockers))->toContain('لا يوجد مزوّد أساسي');

    // Wrong primary (openai) would change the route ⇒ blocked.
    $openai->forceFill(['is_primary' => true])->save();
    CatalogCache::flush();
    $preview = $this->cutover->previewRoutingMode('db');
    expect($preview->sameRoute())->toBeFalse()
        ->and(fn () => $this->cutover->switchRoutingMode('db', 'env', $preview->expectedConfirmation()))->toThrow(CutoverBlockedException::class, 'يجب ألا تغيّر المسار');

    // Set Groq as primary through the service (env mode: no runtime effect yet).
    $openai->forceFill(['is_primary' => false])->save();
    CatalogCache::flush();
    $p = $this->cutover->previewPrimary($groq);
    expect($p->after->selectedHandle())->toBe('groq:llama-3.3-70b-versatile')->and($p->applicable())->toBeTrue();
    $this->cutover->setPrimary($groq, null, 'groq:llama-3.3-70b-versatile');
    expect($groq->fresh()->is_primary)->toBeTrue()->and(app(RoutingPreference::class)->resolve()->source)->toBe('env');

    // Catalog source not database ⇒ blocked.
    config(['ai.catalog_source' => 'config']);
    CatalogCache::flush();
    expect(implode(' ', $this->cutover->previewRoutingMode('db')->blockers))->toContain('ليس المصدر الفعّال');
    config(['ai.catalog_source' => 'database']);
    CatalogCache::flush();

    $preview = $this->cutover->previewRoutingMode('db');
    expect($preview->sameRoute())->toBeTrue()->and($preview->applicable())->toBeTrue();

    expect(fn () => $this->cutover->switchRoutingMode('db', 'env', 'groq'))->toThrow(CutoverBlockedException::class, 'provider:model')
        ->and(fn () => $this->cutover->switchRoutingMode('db', 'db', 'groq:llama-3.3-70b-versatile'))->toThrow(StaleCutoverException::class);

    $this->cutover->switchRoutingMode('db', 'env', 'groq:llama-3.3-70b-versatile');
    $log = AuditLog::where('action', AuditActions::AiRoutingModeChanged)->firstOrFail();

    expect(app(RoutingPreference::class)->mode())->toBe('db')
        ->and(app(RoutingPreference::class)->resolve()->source)->toBe('db')
        ->and(app(RoutingSimulator::class)->current()->selectedHandle())->toBe('groq:llama-3.3-70b-versatile')
        ->and($log->changes()['ai.routing.mode'])->toBe(['from' => 'env', 'to' => 'db'])
        ->and($log->context()['same_route'])->toBeTrue()
        ->and($log->context()['expected_current'])->toBe('env');
});

it('after db mode, the real provider changes ONLY through setPrimary: readiness, exact-credential health, provider:model confirmation, stale conflict, audit; then a clean rollback', function () {
    ['groq' => $groq, 'openai' => $openai] = $this->fx;
    $this->cutover->setPrimary($groq, null, 'groq:llama-3.3-70b-versatile');
    $this->cutover->switchRoutingMode('db', 'env', 'groq:llama-3.3-70b-versatile');

    // Stale: someone else's view of the primary.
    expect(fn () => $this->cutover->setPrimary($openai, null, 'openai:gpt-4.1-mini'))->toThrow(StaleCutoverException::class)
        ->and($openai->fresh()->is_primary)->toBeFalse()->and($groq->fresh()->is_primary)->toBeTrue();

    // Health proof must be for the exact env key of openai.
    ProviderHealthCheck::where('provider_id', $openai->id)->delete();
    $preview = $this->cutover->previewPrimary($openai);
    expect($preview->after->selectedHandle())->toBe('openai:gpt-4.1-mini')
        ->and(implode(' ', $preview->blockers))->toContain('لا فحص مصادقة ناجح لنفس المفتاح');
    c4EnvHealth($openai);

    // Provider name only ⇒ refused; the simulation's provider:model is required.
    expect(fn () => $this->cutover->setPrimary($openai, $groq->id, 'openai'))->toThrow(CutoverBlockedException::class, 'openai:gpt-4.1-mini');

    $this->cutover->setPrimary($openai, $groq->id, 'openai:gpt-4.1-mini');
    $log = AuditLog::where('action', AuditActions::AiRoutingPrimaryChanged)->latest('id')->firstOrFail();

    expect($openai->fresh()->is_primary)->toBeTrue()->and($groq->fresh()->is_primary)->toBeFalse()
        ->and(AiProvider::where('is_primary', true)->count())->toBe(1)
        ->and(app(RoutingPreference::class)->preferredProvider())->toBe('openai')
        ->and(app(RoutingSimulator::class)->current()->selectedHandle())->toBe('openai:gpt-4.1-mini')
        ->and($log->changes()['primary'])->toBe(['from' => 'groq', 'to' => 'openai'])
        ->and($log->context()['route_before'])->toBe('groq:llama-3.3-70b-versatile')
        ->and($log->context()['route_after'])->toBe('openai:gpt-4.1-mini')
        ->and($log->context()['expected_current'])->toBe((string) $groq->id);

    // is_primary stays locked in the generic provider editor.
    expect(fn () => app(CatalogAdmin::class)->updateProvider($groq, ['name' => 'g', 'base_url' => null, 'priority' => 100, 'is_enabled' => true, 'capabilities' => ['chat'], 'is_primary' => true]))
        ->toThrow(CatalogValidationException::class, 'is_primary');

    // Rollback to env: allowed although the route changes back; needs the resulting handle.
    $preview = $this->cutover->previewRoutingMode('env');
    expect($preview->sameRouteRequired)->toBeFalse()->and($preview->expectedConfirmation())->toBe('groq:llama-3.3-70b-versatile');
    $this->cutover->switchRoutingMode('env', 'db', 'groq:llama-3.3-70b-versatile');
    expect(app(RoutingPreference::class)->mode())->toBe('env')
        ->and(app(RoutingSimulator::class)->current()->selectedHandle())->toBe('groq:llama-3.3-70b-versatile')
        ->and(AuditLog::where('action', AuditActions::AiRoutingModeChanged)->count())->toBe(2);
});

it('vault-mode readiness proves the exact active vault credential: a rotation invalidates the previous proof until the new row is verified', function () {
    ['groq' => $groq] = $this->fx;
    c3VaultOn();
    config(['ai.credentials_mode' => 'vault']);
    $manager = app(CredentialManager::class);
    $this->cutover = app(RoutingCutover::class); // fresh instance: the vault key was set above

    $a = $manager->create($groq, new SecretString('gsk_C4_A_0001'));
    c3Verify($a);
    $manager->activate($a, null);

    // The activation proof of row A is a valid readiness proof (same credential_id, fresh).
    expect($this->cutover->previewPrimary($groq)->applicable())->toBeTrue();

    // Env proofs do not count any more: the runtime credential is vault row A.
    ProviderHealthCheck::where('credential_id', $a->id)->delete();
    expect(implode(' ', $this->cutover->previewPrimary($groq)->blockers))->toContain('لا فحص مصادقة ناجح لنفس المفتاح');
    c3Verify($a);
    expect($this->cutover->previewPrimary($groq)->applicable())->toBeTrue();

    // Rotate to B: B verified for activation, but readiness needs a proof after B is the runtime credential.
    $b = $manager->create($groq, new SecretString('gsk_C4_B_0002'));
    c3Verify($b, 'ok', now()->subMinutes(31)->toImmutable());
    c3Verify($b);
    $manager->activate($b, $a->id);
    ProviderHealthCheck::where('credential_id', $b->id)->delete();

    expect(implode(' ', $this->cutover->previewPrimary($groq)->blockers))->toContain('لا فحص مصادقة ناجح لنفس المفتاح')
        ->and($b->fresh()->status)->toBe(CredentialStatus::Active);

    c3Verify($b);
    expect($this->cutover->previewPrimary($groq)->applicable())->toBeTrue();
});

it('a failed-closed provider, a disabled target, or an unknown adapter cannot become primary', function () {
    ['groq' => $groq, 'openai' => $openai] = $this->fx;
    $this->cutover->setPrimary($groq, null, 'groq:llama-3.3-70b-versatile');

    $openai->update(['is_enabled' => false]);
    CatalogCache::flush();
    expect(implode(' ', $this->cutover->previewPrimary($openai->fresh())->blockers))->toContain('معطّل');
    $openai->update(['is_enabled' => true]);

    config(['ai.providers.openai.api_key' => '']);
    CatalogCache::flush();
    expect(implode(' ', $this->cutover->previewPrimary($openai->fresh())->blockers))->toContain('لا مفتاح صالح');

    $ghost = AiProvider::factory()->create(['key' => 'gemini', 'driver' => 'gemini']);
    expect(implode(' ', $this->cutover->previewPrimary($ghost)->blockers))->toContain('لا يوجد adapter');
});
