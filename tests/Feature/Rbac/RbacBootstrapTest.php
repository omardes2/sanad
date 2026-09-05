<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Rbac\RbacSynchronizer;
use App\Support\Audit\AuditActions;
use App\Support\Rbac\Permission;
use App\Support\Rbac\Role;
use App\Support\Rbac\RoleMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;

uses(RefreshDatabase::class);

it('the role matrix grants credentials management to super_admin only and costs to finance only', function () {
    foreach ([Role::Operations, Role::Finance, Role::Support] as $role) {
        expect(RoleMatrix::grants($role, Permission::AiCredentialsManage))->toBeFalse()
            ->and(RoleMatrix::grants($role, Permission::RbacManage))->toBeFalse()
            ->and(RoleMatrix::grants($role, Permission::DashboardAccess))->toBeTrue();
    }

    expect(RoleMatrix::grants(Role::Finance, Permission::UsageViewCosts))->toBeTrue()
        ->and(RoleMatrix::grants(Role::Operations, Permission::UsageViewCosts))->toBeFalse()
        ->and(RoleMatrix::grants(Role::Support, Permission::UsageViewCosts))->toBeFalse()
        ->and(RoleMatrix::grants(Role::Support, Permission::PlansManage))->toBeFalse()
        ->and(count(RoleMatrix::permissionsFor(Role::SuperAdmin)))->toBe(count(Permission::cases()));
});

it('bootstrap is a dry run by default and writes nothing', function () {
    User::factory()->create(['is_admin' => true]);

    $this->artisan('sanad:rbac:bootstrap')
        ->expectsOutputToContain('Dry run')
        ->expectsOutputToContain('needs --promote-admins')
        ->assertSuccessful();

    expect(RoleModel::count())->toBe(0)
        ->and(PermissionModel::count())->toBe(0)
        ->and(AuditLog::count())->toBe(0);
});

it('bootstrap --apply creates roles/permissions from the registry, is idempotent, and audits itself', function () {
    $this->artisan('sanad:rbac:bootstrap', ['--apply' => true])->assertSuccessful();

    expect(RoleModel::count())->toBe(count(Role::cases()))
        ->and(PermissionModel::count())->toBe(count(Permission::cases()))
        ->and(RoleModel::findByName(Role::Finance->value)->permissions->pluck('name')->sort()->values()->all())
        ->toBe(collect(RoleMatrix::permissionsFor(Role::Finance))->sort()->values()->all())
        ->and(AuditLog::where('action', AuditActions::RbacBootstrapApplied)->count())->toBe(1)
        ->and(AuditLog::first()->actor)->toBe('console')
        ->and(AuditLog::first()->user_id)->toBeNull();

    $this->artisan('sanad:rbac:bootstrap', ['--apply' => true])
        ->expectsOutputToContain('Nothing to do')
        ->assertSuccessful();

    expect(RoleModel::count())->toBe(count(Role::cases()))
        ->and(PermissionModel::count())->toBe(count(Permission::cases()));
});

it('bootstrap re-syncs a drifted role to the matrix and keeps unknown permissions untouched', function () {
    rbacSync();
    $support = RoleModel::findByName(Role::Support->value);
    $support->givePermissionTo(PermissionModel::findByName(Permission::AiCredentialsManage->value)); // drift
    PermissionModel::create(['name' => 'legacy.custom', 'guard_name' => 'web']);

    $plan = app(RbacSynchronizer::class)->plan();

    expect($plan->rolePermissionChanges[Role::Support->value]['remove'])->toBe([Permission::AiCredentialsManage->value])
        ->and($plan->unknownPermissions)->toBe(['legacy.custom']);

    $this->artisan('sanad:rbac:bootstrap', ['--apply' => true])->expectsOutputToContain('revoke')->assertSuccessful();

    expect($support->fresh()->hasPermissionTo(Permission::AiCredentialsManage->value))->toBeFalse()
        ->and(PermissionModel::where('name', 'legacy.custom')->exists())->toBeTrue();
});

it('promotes legacy is_admin accounts to super_admin only with --promote-admins, never touching users that already have a role', function () {
    $legacy = User::factory()->create(['is_admin' => true]);
    $plain = User::factory()->create(['is_admin' => false]);
    rbacSync();
    $already = User::factory()->create(['is_admin' => true]);
    $already->assignRole(Role::Support->value);

    $this->artisan('sanad:rbac:bootstrap', ['--apply' => true])->assertSuccessful();
    expect($legacy->fresh()->roles)->toHaveCount(0);

    $this->artisan('sanad:rbac:bootstrap', ['--apply' => true, '--promote-admins' => true])->assertSuccessful();

    expect($legacy->fresh()->hasRole(Role::SuperAdmin->value))->toBeTrue()
        ->and($plain->fresh()->roles)->toHaveCount(0)
        ->and($already->fresh()->hasRole(Role::SuperAdmin->value))->toBeFalse() // kept its explicit role
        ->and($already->fresh()->hasRole(Role::Support->value))->toBeTrue()
        ->and(AuditLog::where('action', AuditActions::RbacRoleAssigned)->where('subject_id', $legacy->id)->count())->toBe(1);

    // Idempotent: nothing left to promote.
    $this->artisan('sanad:rbac:bootstrap', ['--apply' => true, '--promote-admins' => true])
        ->expectsOutputToContain('Nothing to do')
        ->assertSuccessful();
});

it('no migration writes roles, permissions or role assignments', function () {
    expect(RoleModel::count())->toBe(0)
        ->and(PermissionModel::count())->toBe(0);
});
