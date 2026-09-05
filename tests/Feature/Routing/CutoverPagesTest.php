<?php

declare(strict_types=1);

use App\Livewire\Dashboard\Ai\Cutover as CutoverPage;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Ai\Catalog\CatalogCache;
use App\Services\Ai\Catalog\CatalogSourceResolver;
use App\Services\Ai\Routing\RoutingPreference;
use App\Support\Audit\AuditActions;
use App\Support\Rbac\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('cutover page: guest → login; no role, legacy admin, operations, finance, support → 403; super_admin → 200 with the nav link', function () {
    $this->get(route('dashboard.ai.cutover'))->assertRedirect(route('login'));

    rbacSync();
    foreach ([User::factory()->create(['is_admin' => true]), User::factory()->create(['is_admin' => false]), userWithRole(Role::Operations), userWithRole(Role::Finance), userWithRole(Role::Support)] as $user) {
        $this->actingAs($user)->get(route('dashboard.ai.cutover'))->assertForbidden();
    }

    $this->actingAs(userWithRole(Role::Operations))->get(route('dashboard'))->assertDontSee(route('dashboard.ai.cutover'));
    $this->actingAs(userWithRole(Role::SuperAdmin))->get(route('dashboard'))->assertSee(route('dashboard.ai.cutover'));
    $this->actingAs(userWithRole(Role::SuperAdmin))->get(route('dashboard.ai.cutover'))->assertOk();

    Livewire::actingAs(userWithRole(Role::Operations))->test(CutoverPage::class)->assertForbidden();
});

it('walks the safe sequence on the page: Stage B what-if (COST UNKNOWN, no write) → catalog cutover with the typed provider:model → Groq primary → env→db with the same route → degraded banner when the primary is disabled', function () {
    $super = userWithRole(Role::SuperAdmin);
    $this->actingAs($super);
    $fx = c4Catalog();

    $page = Livewire::actingAs($super)->test(CutoverPage::class)
        ->assertOk()
        ->call('whatIf')
        ->assertSee('COST UNKNOWN')
        ->assertSee('groq:llama-3.3-70b-versatile');
    expect(app(CatalogSourceResolver::class)->mode())->toBe('config')->and(AuditLog::where('action', 'like', 'ai.%')->count())->toBe(0);

    // Stage C through the page: preview, wrong confirmation, right confirmation.
    $page->set('catalogTarget', 'database')->call('previewCatalog')
        ->assertSee('provider:model')
        ->set('confirmations.catalog_source', 'groq')->call('applyCatalog')
        ->assertSee('اكتب المسار الناتج');
    expect(app(CatalogSourceResolver::class)->mode())->toBe('config');

    $page->set('confirmations.catalog_source', 'groq:llama-3.3-70b-versatile')->call('applyCatalog')
        ->assertSee('تم تغيير مصدر الكتالوج');
    expect(app(CatalogSourceResolver::class)->mode())->toBe('database')
        ->and(AuditLog::where('action', AuditActions::AiCatalogSourceChanged)->count())->toBe(1);

    // Primary = groq (no route change), then env → db with the same route.
    $page->set('primaryTarget', (string) $fx['groq']->id)->call('previewPrimary')
        ->set('confirmations.primary', 'groq:llama-3.3-70b-versatile')->call('applyPrimary')
        ->assertSee('أصبح [groq] المزوّد الأساسي');
    $page->set('modeTarget', 'db')->call('previewMode')
        ->assertSee('المسار لا يتغيّر')
        ->set('confirmations.routing_mode', 'groq:llama-3.3-70b-versatile')->call('applyMode')
        ->assertSee('تم تغيير وضع التوجيه إلى [db]');
    expect(app(RoutingPreference::class)->resolve()->source)->toBe('db')
        ->and(AuditLog::where('action', AuditActions::AiRoutingPrimaryChanged)->count())->toBe(1)
        ->and(AuditLog::where('action', AuditActions::AiRoutingModeChanged)->count())->toBe(1);

    // Emergency: the primary gets disabled by hand → degraded banner, stored mode untouched.
    $fx['groq']->forceFill(['is_enabled' => false])->save();
    CatalogCache::flush();
    Livewire::actingAs($super)->test(CutoverPage::class)->assertSee('DEGRADED / ENV FALLBACK');
    expect(app(RoutingPreference::class)->mode())->toBe('db');
});
