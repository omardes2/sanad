<?php

declare(strict_types=1);

use App\Livewire\Dashboard\AuditLogs;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Rbac\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders audit rows with redacted metadata and filters by action', function () {
    $viewer = userWithRole(Role::Finance);
    $actor = User::factory()->create(['name' => 'عمر المدير']);

    AuditLog::factory()->create(['user_id' => $actor->id, 'actor' => 'user', 'action' => 'rbac.role_assigned', 'metadata' => ['changes' => ['roles' => ['from' => [], 'to' => ['support']]], 'context' => []]]);
    AuditLog::factory()->create(['user_id' => null, 'actor' => 'console', 'action' => 'settings.updated', 'metadata' => ['changes' => ['api_key' => ['from' => null, 'to' => 'raw-secret-that-slipped-in']], 'context' => []]]);

    Livewire::actingAs($viewer)
        ->test(AuditLogs::class)
        ->assertOk()
        ->assertSee('سجل التدقيق')
        ->assertSee('rbac.role_assigned')
        ->assertSee('عمر المدير')
        ->assertSee('settings.updated')
        ->assertDontSee('raw-secret-that-slipped-in') // defensive redaction on render
        ->set('action', 'rbac.')
        ->assertSee('rbac.role_assigned')
        ->assertDontSee('settings.updated');
});

it('is refused inside the component for a user without the permission, even a legacy admin', function () {
    rbacSync();
    $legacy = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($legacy)->test(AuditLogs::class)->assertForbidden();
    Livewire::actingAs(userWithRole(Role::Support))->test(AuditLogs::class)->assertForbidden();
});
