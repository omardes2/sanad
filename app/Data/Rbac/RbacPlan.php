<?php

declare(strict_types=1);

namespace App\Data\Rbac;

/**
 * What `sanad:rbac:bootstrap` would change, computed before anything is
 * written so it can be printed as a dry run.
 *
 * @param  list<string>  $permissionsToCreate
 * @param  list<string>  $rolesToCreate
 * @param  array<string, array{add: list<string>, remove: list<string>}>  $rolePermissionChanges
 * @param  list<string>  $unknownPermissions  rows in the DB that the registry does not know (kept, reported)
 * @param  list<array{id: int, name: string, email: ?string}>  $adminsToPromote
 */
final readonly class RbacPlan
{
    /**
     * @param  list<string>  $permissionsToCreate
     * @param  list<string>  $rolesToCreate
     * @param  array<string, array{add: list<string>, remove: list<string>}>  $rolePermissionChanges
     * @param  list<string>  $unknownPermissions
     * @param  list<array{id: int, name: string, email: ?string}>  $adminsToPromote
     */
    public function __construct(
        public array $permissionsToCreate,
        public array $rolesToCreate,
        public array $rolePermissionChanges,
        public array $unknownPermissions,
        public array $adminsToPromote,
    ) {}

    public function hasSchemaChanges(): bool
    {
        if ($this->permissionsToCreate !== [] || $this->rolesToCreate !== []) {
            return true;
        }

        foreach ($this->rolePermissionChanges as $change) {
            if ($change['add'] !== [] || $change['remove'] !== []) {
                return true;
            }
        }

        return false;
    }
}
