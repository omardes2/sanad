<?php

declare(strict_types=1);

use App\Enums\ToolCapability;
use App\Models\Memory;
use App\Models\Reminder;
use App\Models\User;
use App\Support\Rbac\Permission;
use App\Support\Rbac\Role;
use App\Support\Rbac\RoleMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Strict-RBAC pages: every role, positive and negative
|--------------------------------------------------------------------------
*/

/** route name => the permission that opens it. */
function adminSurfaceRoutes(): array
{
    return [
        'dashboard.launch' => Permission::LaunchReadinessView,
        'dashboard.tools.invocations' => Permission::ToolsInvocationsView,
        'dashboard.tools.consents' => Permission::ToolsConsentsView,
        'dashboard.memory' => Permission::MemoryOperationsView,
    ];
}

it('lets super_admin open every new admin page', function (string $route) {
    $this->actingAs(userWithRole(Role::SuperAdmin))->get(route($route))->assertOk();
})->with(array_keys(adminSurfaceRoutes()));

it('opens a new admin page exactly for the roles the matrix grants', function (string $route) {
    $permission = adminSurfaceRoutes()[$route];

    foreach ([Role::Operations, Role::Finance, Role::Support] as $role) {
        $expected = RoleMatrix::grants($role, $permission);
        $response = $this->actingAs(userWithRole($role))->get(route($route));

        $expected
            ? $response->assertOk()
            : $response->assertForbidden();
    }
})->with(array_keys(adminSurfaceRoutes()));

it('refuses a legacy is_admin account with no role on every strict page', function (string $route) {
    // These pages are POST-RBAC: no legacy bypass, fail closed.
    $legacy = User::factory()->create(['is_admin' => true]);

    $this->actingAs($legacy)->get(route($route))->assertForbidden();
})->with(array_keys(adminSurfaceRoutes()));

it('redirects a guest from every new admin page', function (string $route) {
    $this->get(route($route))->assertRedirect(route('login'));
})->with(array_keys(adminSurfaceRoutes()));

it('forbids a non-admin authenticated user from every new admin page', function (string $route) {
    $this->actingAs(User::factory()->create(['is_admin' => false]))->get(route($route))->assertForbidden();
})->with(array_keys(adminSurfaceRoutes()));

it('gates the new detail pages on the same permission as their list', function () {
    $subscriber = User::factory()->create();
    $invocation = admInvocation($subscriber);
    $consent = admConsent($subscriber, ToolCapability::MemoryRead);

    $allowed = userWithRole(Role::Operations);
    $denied = userWithRole(Role::Finance);

    $this->actingAs($allowed)->get(route('dashboard.tools.invocations.show', $invocation))->assertOk();
    $this->actingAs($denied)->get(route('dashboard.tools.invocations.show', $invocation))->assertForbidden();

    $this->actingAs($allowed)->get(route('dashboard.tools.consents.show', $consent))->assertOk();
    $this->actingAs($denied)->get(route('dashboard.tools.consents.show', $consent))->assertForbidden();

    $this->actingAs($allowed)->get(route('dashboard.memory.subscriber', $subscriber))->assertOk();
    $this->actingAs($denied)->get(route('dashboard.memory.subscriber', $subscriber))->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Legacy pages: the gate moved to the route, and legacy admins keep access
|--------------------------------------------------------------------------
*/

/** route name => the permission a ROLE account needs (legacy is_admin bypasses). */
function legacyGatedRoutes(): array
{
    return [
        'dashboard.conversations' => Permission::ConversationsView,
        'dashboard.messages' => Permission::MessagesContentView,
        'dashboard.tasks' => Permission::TasksView,
        'dashboard.reminders' => Permission::RemindersView,
        'dashboard.expenses' => Permission::ExpensesView,
        'dashboard.whatsapp' => Permission::WhatsAppStatusView,
    ];
}

it('keeps a legacy is_admin account on every page that pre-dates RBAC', function (string $route) {
    $legacy = User::factory()->create(['is_admin' => true]);

    $this->actingAs($legacy)->get(route($route))->assertOk();
})->with(array_keys(legacyGatedRoutes()));

it('gates a legacy page for a ROLE account exactly as the matrix says', function (string $route) {
    $permission = legacyGatedRoutes()[$route];

    foreach ([Role::Operations, Role::Finance, Role::Support] as $role) {
        $response = $this->actingAs(userWithRole($role))->get(route($route));

        RoleMatrix::grants($role, $permission)
            ? $response->assertOk()
            : $response->assertForbidden();
    }
})->with(array_keys(legacyGatedRoutes()));

/*
|--------------------------------------------------------------------------
| Least privilege: Support sees that a subscriber wrote in, not what they said
|--------------------------------------------------------------------------
*/

it('gives Support conversation metadata but never message content', function () {
    $support = userWithRole(Role::Support);

    expect($support->can(Permission::ConversationsView->value))->toBeTrue()
        ->and($support->can(Permission::MessagesContentView->value))->toBeFalse();

    $this->actingAs($support)->get(route('dashboard.conversations'))->assertOk();
    $this->actingAs($support)->get(route('dashboard.messages'))->assertForbidden();
});

it('never gives Support memory metadata, tool invocations or delivery internals', function () {
    $support = userWithRole(Role::Support);

    expect($support->can(Permission::MemoryOperationsView->value))->toBeFalse()
        ->and($support->can(Permission::ToolsInvocationsView->value))->toBeFalse()
        ->and($support->can(Permission::RemindersDeliveryView->value))->toBeFalse();
});

it('has no permission anywhere that reveals memory content', function () {
    foreach (Permission::cases() as $permission) {
        expect($permission->value)->not->toContain('memory.content')
            ->and($permission->value)->not->toContain('reveal');
    }

    // And no route exposes one either.
    $names = collect(app('router')->getRoutes())->map(fn ($r) => $r->getName())->filter()->all();

    foreach ($names as $name) {
        expect($name)->not->toContain('reveal');
    }
});

it('grants no role a permission outside the registry', function () {
    foreach (Role::cases() as $role) {
        foreach (RoleMatrix::permissionsFor($role) as $permission) {
            expect(Permission::tryFrom($permission))->not->toBeNull();
        }
    }
});

/*
|--------------------------------------------------------------------------
| Consent: revoke is allowed, grant does not exist
|--------------------------------------------------------------------------
*/

it('offers no grant action on any consent surface', function () {
    $consent = admConsent(User::factory()->create(), ToolCapability::MemoryRead);

    $response = $this->actingAs(userWithRole(Role::SuperAdmin))->get(route('dashboard.tools.consents.show', $consent));

    $response->assertOk()
        ->assertDontSee('wire:click="grant"', false)
        ->assertSee('لا يوجد زر منح');
});

it('shows the reminder delivery columns only with the delivery permission', function () {
    $subscriber = User::factory()->create();
    Reminder::factory()->for($subscriber)->create([
        'title' => 'تذكير الاختبار',
        'last_error' => 'template_required',
    ]);

    // Operations holds reminders.delivery.view.
    $this->actingAs(userWithRole(Role::Operations))
        ->get(route('dashboard.reminders'))
        ->assertOk()
        ->assertSee('سبب الفشل');

    // Support holds reminders.view only.
    $this->actingAs(userWithRole(Role::Support))
        ->get(route('dashboard.reminders'))
        ->assertOk()
        ->assertDontSee('سبب الفشل');
});

it('hides memory counts from a role without the memory permission even by direct url', function () {
    $subscriber = User::factory()->create();
    Memory::factory()->for($subscriber, 'user')->create();

    $this->actingAs(userWithRole(Role::Support))
        ->get(route('dashboard.memory.subscriber', $subscriber))
        ->assertForbidden();
});
