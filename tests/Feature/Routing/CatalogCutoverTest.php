<?php

declare(strict_types=1);

use App\Exceptions\Routing\CutoverBlockedException;
use App\Exceptions\Routing\StaleCutoverException;
use App\Models\AuditLog;
use App\Models\ModelPrice;
use App\Models\ProviderHealthCheck;
use App\Services\Ai\Catalog\CatalogCache;
use App\Services\Ai\Catalog\CatalogSourceResolver;
use App\Services\Ai\Catalog\RoutingSimulator;
use App\Services\Ai\Routing\RoutingCutover;
use App\Support\Audit\AuditActions;
use App\Support\Rbac\Permission;
use App\Support\Rbac\Role;
use App\Support\Rbac\RoleMatrix;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(userWithRole(Role::SuperAdmin));
    $this->fx = c4Catalog();
    $this->cutover = app(RoutingCutover::class);
});

it('Stage C same-route guard: config → database is blocked when the database route differs, allowed when it is the same', function () {
    // Make the DB route differ: groq's model is disabled in the database only
    // (the preferred provider wins ties, so priority alone would not do it).
    $this->fx['llama']->update(['is_enabled' => false]);
    CatalogCache::flush();

    $preview = $this->cutover->previewCatalogSource('database');
    expect($preview->sameRoute())->toBeFalse()
        ->and(implode(' ', $preview->blockers))->toContain('يجب ألا تغيّر المسار')
        ->and(fn () => $this->cutover->switchCatalogSource('database', 'config', $preview->expectedConfirmation()))->toThrow(CutoverBlockedException::class)
        ->and(app(CatalogSourceResolver::class)->mode())->toBe('config');

    $this->fx['llama']->update(['is_enabled' => true]);
    CatalogCache::flush();

    $preview = $this->cutover->previewCatalogSource('database');
    expect($preview->sameRoute())->toBeTrue()->and($preview->applicable())->toBeTrue()->and($preview->expectedConfirmation())->toBe('groq:llama-3.3-70b-versatile');

    // A provider name alone is not a confirmation.
    expect(fn () => $this->cutover->switchCatalogSource('database', 'config', 'groq'))->toThrow(CutoverBlockedException::class, 'provider:model');

    $this->cutover->switchCatalogSource('database', 'config', 'groq:llama-3.3-70b-versatile');
    $log = AuditLog::where('action', AuditActions::AiCatalogSourceChanged)->firstOrFail();

    expect(app(CatalogSourceResolver::class)->mode())->toBe('database')
        ->and(app(CatalogSourceResolver::class)->activeName())->toBe('database')
        ->and(app(RoutingSimulator::class)->current()->selectedHandle())->toBe('groq:llama-3.3-70b-versatile')
        ->and($log->changes()['ai.catalog_source'])->toBe(['from' => 'config', 'to' => 'database'])
        ->and($log->context()['same_route'])->toBeTrue()
        ->and($log->context()['confirmation'])->toBe('groq:llama-3.3-70b-versatile')
        ->and(collect($log->context()['readiness'])->pluck('status', 'key')->all())->toMatchArray(['provider' => 'ok', 'models' => 'ok', 'credential' => 'ok', 'health' => 'ok']);
});

it('readiness blocks the catalog cutover: a health proof for a DIFFERENT env key, or none, is not readiness; pricing is only a warning', function () {
    ProviderHealthCheck::query()->delete();
    $preview = $this->cutover->previewCatalogSource('database');
    expect(implode(' ', $preview->blockers))->toContain('لا فحص مصادقة ناجح لنفس المفتاح');

    // A probe of a previous key value does not count once the key changed.
    c4EnvHealth($this->fx['groq'], 'old-groq-key');
    expect(implode(' ', $this->cutover->previewCatalogSource('database')->blockers))->toContain('لا فحص مصادقة ناجح لنفس المفتاح');

    c4EnvHealth($this->fx['groq']);
    $preview = $this->cutover->previewCatalogSource('database');
    expect($preview->applicable())->toBeTrue()->and(implode(' ', $preview->warnings))->toContain('COST UNKNOWN');

    ModelPrice::factory()->for($this->fx['llama'], 'model')->create();
    expect($this->cutover->previewCatalogSource('database')->warnings)->toBe([]);
});

it('stale state, environment override, no-op target and last viable route are refused; database → config rollback is allowed with the resulting handle', function () {
    expect(fn () => $this->cutover->switchCatalogSource('database', 'auto', 'groq:llama-3.3-70b-versatile'))->toThrow(StaleCutoverException::class)
        ->and(app(CatalogSourceResolver::class)->mode())->toBe('config')
        ->and(fn () => $this->cutover->switchCatalogSource('config', 'config', 'groq:llama-3.3-70b-versatile'))->toThrow(CutoverBlockedException::class, 'بالفعل');

    config(['ai.overrides.catalog_source' => 'config']);
    expect(fn () => $this->cutover->switchCatalogSource('database', 'config', 'groq:llama-3.3-70b-versatile'))->toThrow(CutoverBlockedException::class, 'AI_CATALOG_SOURCE');
    config(['ai.overrides.catalog_source' => null]);

    $this->cutover->switchCatalogSource('database', 'config', 'groq:llama-3.3-70b-versatile');

    // Rollback: even if the config route differed it is allowed; here it needs the resulting handle.
    config(['ai.providers.groq.api_key' => '']);
    CatalogCache::flush();
    $preview = $this->cutover->previewCatalogSource('config');
    expect($preview->sameRouteRequired)->toBeFalse()->and($preview->expectedConfirmation())->toBe('openai:gpt-4.1-mini');
    $this->cutover->switchCatalogSource('config', 'database', 'openai:gpt-4.1-mini');
    expect(app(CatalogSourceResolver::class)->mode())->toBe('config')
        ->and(AuditLog::where('action', AuditActions::AiCatalogSourceChanged)->count())->toBe(2);

    // Last viable route: no configured provider at all ⇒ blocked.
    config(['ai.providers.openai.api_key' => '']);
    CatalogCache::flush();
    expect(fn () => $this->cutover->switchCatalogSource('database', 'config', 'x:y'))->toThrow(CutoverBlockedException::class, 'last viable route');
});

it('is reserved to super_admin with ai.routing.cutover — operations holding ai.routing.manage cannot preview or switch', function () {
    $this->actingAs(userWithRole(Role::Operations));

    expect(fn () => app(RoutingCutover::class)->previewCatalogSource('database'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RoutingCutover::class)->switchCatalogSource('database', 'config', 'groq:llama-3.3-70b-versatile'))->toThrow(AuthorizationException::class)
        ->and(RoleMatrix::grants(Role::Operations, Permission::AiRoutingCutover))->toBeFalse()
        ->and(RoleMatrix::grants(Role::Finance, Permission::AiRoutingCutover))->toBeFalse();
});
