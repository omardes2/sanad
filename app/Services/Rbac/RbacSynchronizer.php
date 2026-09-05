<?php

declare(strict_types=1);

namespace App\Services\Rbac;

use App\Data\Rbac\RbacPlan;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Audit\AuditActions;
use App\Support\Rbac\Permission;
use App\Support\Rbac\Role;
use App\Support\Rbac\RoleMatrix;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Synchronises the database roles/permissions to the code registry
 * (Permission + RoleMatrix) and, on explicit request, promotes the existing
 * `is_admin` accounts to super_admin. Nothing here runs from a migration:
 * plan() is pure, apply() is an explicit, audited, idempotent write.
 */
class RbacSynchronizer
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PermissionRegistrar $registrar,
    ) {}

    public function plan(): RbacPlan
    {
        $guard = $this->guard();

        $existingPermissions = PermissionModel::query()->where('guard_name', $guard)->pluck('name')->all();
        $permissionsToCreate = array_values(array_diff(Permission::values(), $existingPermissions));
        $unknownPermissions = array_values(array_diff($existingPermissions, Permission::values()));

        $existingRoles = RoleModel::query()->where('guard_name', $guard)->with('permissions')->get()->keyBy('name');
        $rolesToCreate = [];
        $rolePermissionChanges = [];

        foreach (Role::cases() as $role) {
            $wanted = RoleMatrix::permissionsFor($role);
            $current = isset($existingRoles[$role->value])
                ? $existingRoles[$role->value]->permissions->pluck('name')->all()
                : [];

            if (! isset($existingRoles[$role->value])) {
                $rolesToCreate[] = $role->value;
            }

            $rolePermissionChanges[$role->value] = [
                'add' => array_values(array_diff($wanted, $current)),
                'remove' => array_values(array_diff($current, $wanted)),
            ];
        }

        $adminsToPromote = User::query()
            ->where('is_admin', true)
            ->whereDoesntHave('roles')
            ->orderBy('id')
            ->get(['id', 'name', 'email'])
            ->map(static fn (User $user): array => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email])
            ->all();

        return new RbacPlan($permissionsToCreate, $rolesToCreate, $rolePermissionChanges, $unknownPermissions, $adminsToPromote);
    }

    /**
     * Apply the plan: create missing permissions/roles, sync every role's
     * permissions to the matrix exactly, and (only when asked) promote the
     * legacy admins. Idempotent — a second run plans nothing.
     */
    public function apply(RbacPlan $plan, bool $promoteAdmins = false, string $actor = 'console'): void
    {
        $guard = $this->guard();

        DB::transaction(function () use ($plan, $promoteAdmins, $guard, $actor): void {
            foreach (Permission::cases() as $permission) {
                PermissionModel::query()->firstOrCreate(['name' => $permission->value, 'guard_name' => $guard]);
            }

            foreach (Role::cases() as $role) {
                /** @var RoleModel $model */
                $model = RoleModel::query()->firstOrCreate(['name' => $role->value, 'guard_name' => $guard]);
                $model->syncPermissions(RoleMatrix::permissionsFor($role));
            }

            $this->registrar->forgetCachedPermissions();

            $this->audit->record(AuditActions::RbacBootstrapApplied, null, [], [
                'permissions_created' => $plan->permissionsToCreate,
                'roles_created' => $plan->rolesToCreate,
                'role_permission_changes' => $plan->rolePermissionChanges,
                'unknown_permissions_kept' => $plan->unknownPermissions,
                'actor' => $actor,
            ]);

            if (! $promoteAdmins) {
                return;
            }

            foreach ($plan->adminsToPromote as $admin) {
                $user = User::query()->find($admin['id']);

                if ($user === null || $user->roles()->exists()) {
                    continue; // changed since the plan was computed — never override
                }

                $user->assignRole(Role::SuperAdmin->value);

                $this->audit->record(AuditActions::RbacRoleAssigned, $user, [
                    'roles' => ['from' => [], 'to' => [Role::SuperAdmin->value]],
                ], ['reason' => 'legacy is_admin promotion', 'actor' => $actor]);
            }

            $this->registrar->forgetCachedPermissions();
        });
    }

    private function guard(): string
    {
        return (string) config('auth.defaults.guard', 'web');
    }
}
