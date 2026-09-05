<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Rbac\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The Phase C0 access matrix, route × actor:
 *
 *  - guest                 → redirected to login everywhere
 *  - authenticated, no role, is_admin=false → 403 everywhere
 *  - legacy is_admin (no role)  → every PRE-RBAC page (compatibility), but
 *                                 NOT the strict-RBAC audit page (fail closed)
 *  - super_admin           → everything
 *  - operations / finance / support → exactly what the RoleMatrix grants
 */
$generalPages = ['dashboard', 'dashboard.conversations', 'dashboard.messages', 'dashboard.tasks', 'dashboard.reminders', 'dashboard.expenses', 'dashboard.whatsapp'];

function subscriberRoute(): string
{
    return route('dashboard.subscribers.show', User::factory()->create(['is_admin' => false]));
}

it('redirects guests from every dashboard page including the audit page', function (string $route) {
    $this->get(route($route))->assertRedirect(route('login'));
})->with([...$generalPages, 'dashboard.plans', 'dashboard.subscribers', 'dashboard.audit']);

it('forbids an authenticated user with neither the legacy flag nor a role', function (string $route) {
    rbacSync();
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)->get(route($route))->assertForbidden();
})->with([...$generalPages, 'dashboard.plans', 'dashboard.subscribers', 'dashboard.audit']);

it('keeps every pre-RBAC page open to a legacy is_admin account without a role', function (string $route) {
    rbacSync();
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)->get(route($route))->assertOk();
})->with([...$generalPages, 'dashboard.plans', 'dashboard.subscribers']);

it('refuses the strict-RBAC audit page to a legacy is_admin account without a role (fail closed)', function () {
    rbacSync();
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)->get(route('dashboard.audit'))->assertForbidden();
});

it('grants super_admin every page', function (string $route) {
    $this->actingAs(userWithRole(Role::SuperAdmin))->get(route($route))->assertOk();
})->with([...$generalPages, 'dashboard.plans', 'dashboard.subscribers', 'dashboard.audit']);

it('operations: general pages, plans and subscribers — but not the audit page', function () {
    $user = userWithRole(Role::Operations);

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
    $this->actingAs($user)->get(route('dashboard.plans'))->assertOk();
    $this->actingAs($user)->get(route('dashboard.subscribers'))->assertOk();
    $this->actingAs($user)->get(subscriberRoute())->assertOk();
    $this->actingAs($user)->get(route('dashboard.audit'))->assertForbidden();
});

it('finance: general pages, subscribers (view) and the audit page — but not plans', function () {
    $user = userWithRole(Role::Finance);

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
    $this->actingAs($user)->get(route('dashboard.subscribers'))->assertOk();
    $this->actingAs($user)->get(route('dashboard.audit'))->assertOk();
    $this->actingAs($user)->get(route('dashboard.plans'))->assertForbidden();
});

it('support: general pages and subscribers — but neither plans nor the audit page', function () {
    $user = userWithRole(Role::Support);

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
    $this->actingAs($user)->get(route('dashboard.subscribers'))->assertOk();
    $this->actingAs($user)->get(subscriberRoute())->assertOk();
    $this->actingAs($user)->get(route('dashboard.plans'))->assertForbidden();
    $this->actingAs($user)->get(route('dashboard.audit'))->assertForbidden();
});

it('shows the audit nav link only to accounts that can view it', function () {
    rbacSync();
    $legacy = User::factory()->create(['is_admin' => true]);

    $this->actingAs($legacy)->get(route('dashboard'))->assertOk()->assertDontSee(route('dashboard.audit'));
    $this->actingAs(userWithRole(Role::Finance))->get(route('dashboard'))->assertOk()->assertSee(route('dashboard.audit'));
    $this->actingAs(userWithRole(Role::SuperAdmin))->get(route('dashboard'))->assertOk()->assertSee(route('dashboard.audit'));
});

it('super_admin passes any Gate ability, other roles only their permissions', function () {
    $super = userWithRole(Role::SuperAdmin);
    $support = userWithRole(Role::Support);

    expect($super->can('ai.credentials.manage'))->toBeTrue()
        ->and($super->can('something.not.registered'))->toBeTrue()
        ->and($support->can('subscribers.manage'))->toBeTrue()
        ->and($support->can('ai.credentials.manage'))->toBeFalse()
        ->and($support->can('usage.view_costs'))->toBeFalse()
        ->and($support->canAccessDashboard())->toBeTrue();
});

it('the Gate::before bypass is granted ONLY by the super_admin role, never by is_admin', function () {
    rbacSync();
    $legacy = User::factory()->create(['is_admin' => true]);
    $legacyWithRole = User::factory()->create(['is_admin' => true]);
    $legacyWithRole->assignRole(Role::Support->value);
    $super = userWithRole(Role::SuperAdmin);

    expect($legacy->can('audit.view'))->toBeFalse()
        ->and($legacy->can('ai.credentials.manage'))->toBeFalse()
        ->and($legacy->can('anything.at.all'))->toBeFalse()
        ->and($legacy->canAccessDashboard())->toBeTrue() // legacy dashboard entry only
        ->and($legacyWithRole->fresh()->can('audit.view'))->toBeFalse() // is_admin adds nothing on top of the role
        ->and($legacyWithRole->fresh()->can('subscribers.manage'))->toBeTrue()
        ->and($super->can('anything.at.all'))->toBeTrue();

    // And on the wire: is_admin + a non-privileged role still cannot open the strict page.
    $this->actingAs($legacyWithRole)->get(route('dashboard.audit'))->assertForbidden();
});
