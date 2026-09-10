<?php

declare(strict_types=1);

use App\Enums\ReminderStatus;
use App\Models\Reminder;
use App\Models\User;
use App\Support\Rbac\Permission;
use App\Support\Rbac\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route as RouteFacade;

uses(RefreshDatabase::class);

/**
 * Every navigation entry, as the layout declares it.
 *
 * @return list<array{route: string, label: string, can: ?string, legacy: bool}>
 */
function navEntries(): array
{
    return [
        ['route' => 'dashboard', 'label' => 'نظرة عامة', 'can' => null, 'legacy' => false],
        ['route' => 'dashboard.launch', 'label' => 'جاهزية الإطلاق V1', 'can' => 'launch.readiness.view', 'legacy' => false],
        ['route' => 'dashboard.whatsapp', 'label' => 'حالة واتساب والطوابير', 'can' => 'whatsapp.status.view', 'legacy' => true],
        ['route' => 'dashboard.audit', 'label' => 'سجل التدقيق', 'can' => 'audit.view', 'legacy' => false],
        ['route' => 'dashboard.tools.invocations', 'label' => 'استدعاءات الأدوات', 'can' => 'tools.invocations.view', 'legacy' => false],
        ['route' => 'dashboard.tools.consents', 'label' => 'موافقات الأدوات', 'can' => 'tools.consents.view', 'legacy' => false],
        ['route' => 'dashboard.subscribers', 'label' => 'المشتركون', 'can' => 'subscribers.view', 'legacy' => true],
        ['route' => 'dashboard.conversations', 'label' => 'المحادثات', 'can' => 'conversations.view', 'legacy' => true],
        ['route' => 'dashboard.messages', 'label' => 'الرسائل', 'can' => 'messages.content.view', 'legacy' => true],
        ['route' => 'dashboard.tasks', 'label' => 'المهام', 'can' => 'tasks.view', 'legacy' => true],
        ['route' => 'dashboard.reminders', 'label' => 'التذكيرات', 'can' => 'reminders.view', 'legacy' => true],
        ['route' => 'dashboard.memory', 'label' => 'الذاكرة الدائمة', 'can' => 'memory.operations.view', 'legacy' => false],
        ['route' => 'dashboard.expenses', 'label' => 'المصروفات', 'can' => 'expenses.view', 'legacy' => true],
    ];
}

it('groups the navigation under headings', function () {
    $response = $this->actingAs(userWithRole(Role::SuperAdmin))->get(route('dashboard'));

    $response->assertOk();

    foreach (['التشغيل', 'الذكاء والأدوات', 'المشتركون', 'المالية', 'النظام'] as $group) {
        $response->assertSee($group);
    }
});

/**
 * The sidebar link for a route, asserted as a full `href="..."` so it cannot be
 * confused with prose. An earlier version of this test matched bare labels and
 * tripped over the overview's own subtitle, which mentions «الرسائل» in a
 * sentence — a navigation test should assert LINKS, not words.
 */
function navLink(string $route): string
{
    return 'href="'.route($route).'"';
}

it('shows super_admin every navigation entry', function () {
    $response = $this->actingAs(userWithRole(Role::SuperAdmin))->get(route('dashboard'));

    $response->assertOk();

    foreach (navEntries() as $entry) {
        $response->assertSee(navLink($entry['route']), false);
    }
});

it('hides a navigation entry from a role that cannot open it', function (string $role) {
    $user = userWithRole(Role::from($role));
    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();

    foreach (navEntries() as $entry) {
        if ($entry['can'] === null) {
            continue;
        }

        $user->can($entry['can'])
            ? $response->assertSee(navLink($entry['route']), false)
            : $response->assertDontSee(navLink($entry['route']), false);
    }
})->with(['operations', 'finance', 'support']);

it('never shows Support a link to message content or to memory', function () {
    $response = $this->actingAs(userWithRole(Role::Support))->get(route('dashboard'));

    $response->assertOk()
        ->assertSee(navLink('dashboard.conversations'), false)
        ->assertDontSee(navLink('dashboard.messages'), false)
        ->assertDontSee(navLink('dashboard.memory'), false)
        ->assertDontSee(navLink('dashboard.tools.invocations'), false);
});

/*
|--------------------------------------------------------------------------
| The sidebar and the router must agree
|--------------------------------------------------------------------------
*/

it('gives a legacy is_admin account every legacy link and no strict one', function () {
    $legacy = User::factory()->create(['is_admin' => true]);
    $response = $this->actingAs($legacy)->get(route('dashboard'));

    $response->assertOk();

    foreach (navEntries() as $entry) {
        if ($entry['can'] === null) {
            continue;
        }

        // The nav's legacy branch mirrors EnsureLegacyAdminOrPermission.
        $entry['legacy']
            ? $response->assertSee(navLink($entry['route']), false)
            : $response->assertDontSee(navLink($entry['route']), false);
    }
});

it('never renders a nav link the account would be refused on', function (string $role) {
    $user = userWithRole(Role::from($role));
    $response = $this->actingAs($user)->get(route('dashboard'));
    $response->assertOk();

    foreach (navEntries() as $entry) {
        if ($entry['can'] === null) {
            continue;
        }

        $visible = $user->can($entry['can']) || ($entry['legacy'] && $user->isAdmin());

        if (! $visible) {
            continue;
        }

        // If the sidebar offers it, the router must honour it.
        $this->actingAs($user)->get(route($entry['route']))->assertOk();
    }
})->with(['operations', 'finance', 'support']);

it('declares a permission for every dashboard route except the overview', function () {
    $unguarded = [];

    foreach (RouteFacade::getRoutes() as $route) {
        $name = $route->getName();

        if ($name === null || ! str_starts_with($name, 'dashboard')) {
            continue;
        }

        $middleware = $route->gatherMiddleware();
        $guarded = collect($middleware)->contains(
            fn ($m) => is_string($m) && (str_starts_with($m, 'permission:') || str_starts_with($m, 'permission.legacy:'))
        );

        if (! $guarded) {
            $unguarded[] = $name;
        }
    }

    // Only the overview is open to any dashboard account, and it renders
    // nothing an account is not separately permitted to see.
    expect($unguarded)->toBe(['dashboard']);
});

it('names a permission that exists in the registry on every dashboard route', function () {
    foreach (RouteFacade::getRoutes() as $route) {
        $name = $route->getName();

        if ($name === null || ! str_starts_with($name, 'dashboard')) {
            continue;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            foreach (['permission:', 'permission.legacy:'] as $prefix) {
                if (str_starts_with($middleware, $prefix)) {
                    $permission = substr($middleware, strlen($prefix));

                    // Named separately so a failure says WHICH route drifted.
                    expect([$name => Permission::tryFrom($permission)?->value])
                        ->not->toBe([$name => null]);
                }
            }
        }
    }
});

/*
|--------------------------------------------------------------------------
| Links on the board obey the same rule as links in the sidebar
|--------------------------------------------------------------------------
|
| Regression: the overview's cards and warnings once linked to the tool
| invocation page for every account, including roles the router refuses. A link
| that can only 403 wastes a click and advertises a page the account is not
| permitted to know about.
*/

it('never links the overview to a page the account cannot open', function (string $role) {
    $user = userWithRole(Role::from($role));
    $subscriber = User::factory()->create();

    admInvocation($subscriber);
    Reminder::factory()->for($subscriber)->create([
        'status' => ReminderStatus::Failed,
        'last_error' => 'too_late',
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));
    $response->assertOk();

    foreach (['dashboard.tools.invocations', 'dashboard.reminders', 'dashboard.usage', 'dashboard.launch'] as $route) {
        if (str_contains($response->getContent(), navLink($route))) {
            // If the board offers the link, the router must honour it.
            $this->actingAs($user)->get(route($route))->assertOk();
        }
    }
})->with(['operations', 'finance', 'support']);
