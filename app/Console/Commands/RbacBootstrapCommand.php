<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Rbac\RbacSynchronizer;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;

/**
 * Synchronises roles/permissions to the code registry and, only when asked,
 * promotes the existing `is_admin` accounts to super_admin. This is the ONLY
 * way roles reach the database — no migration writes data.
 *
 *  - DRY RUN by default: prints exactly what would change and writes nothing.
 *  - --apply writes (in production: confirmation prompt, or --force).
 *  - --promote-admins additionally assigns super_admin to every user with
 *    is_admin = true that has no role yet (explicit opt-in, never implicit).
 *  - Idempotent: a second run reports "nothing to do".
 */
class RbacBootstrapCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'sanad:rbac:bootstrap
        {--apply : Write the changes (default is a dry run)}
        {--promote-admins : Also grant super_admin to existing is_admin users that have no role}
        {--force : Skip the production confirmation prompt}';

    protected $description = 'Sync roles/permissions to the code registry (dry run by default); optionally promote legacy admins';

    public function handle(RbacSynchronizer $rbac): int
    {
        $apply = (bool) $this->option('apply');
        $promote = (bool) $this->option('promote-admins');

        if ($apply && ! $this->confirmToProceed('Application is in production — write roles/permissions?')) {
            return self::FAILURE;
        }

        $plan = $rbac->plan();
        $rows = [];

        foreach ($plan->permissionsToCreate as $name) {
            $rows[] = ['permission', $name, 'create', ''];
        }

        foreach ($plan->unknownPermissions as $name) {
            $rows[] = ['permission', $name, 'keep', 'not in registry — left untouched'];
        }

        foreach ($plan->rolesToCreate as $name) {
            $rows[] = ['role', $name, 'create', ''];
        }

        foreach ($plan->rolePermissionChanges as $role => $change) {
            if ($change['add'] !== []) {
                $rows[] = ['role', $role, 'grant', implode(', ', $change['add'])];
            }

            if ($change['remove'] !== []) {
                $rows[] = ['role', $role, 'revoke', implode(', ', $change['remove'])];
            }
        }

        foreach ($plan->adminsToPromote as $admin) {
            $rows[] = ['user', "#{$admin['id']} {$admin['name']}", $promote ? 'promote → super_admin' : 'needs --promote-admins', 'is_admin without any role'];
        }

        $this->line($apply ? '<info>Applying</info> RBAC bootstrap:' : '<comment>Dry run</comment> — nothing will be written (add --apply):');

        if ($rows === []) {
            $this->info('Nothing to do: database already matches the registry and every admin has a role.');
        } else {
            $this->table(['Kind', 'Name', 'Action', 'Details'], $rows);
        }

        if (! $apply) {
            return self::SUCCESS;
        }

        $rbac->apply($plan, promoteAdmins: $promote);
        $this->info('RBAC bootstrap applied.');

        if (! $promote && $plan->adminsToPromote !== []) {
            $this->warn(count($plan->adminsToPromote).' legacy admin(s) still have no role — re-run with --apply --promote-admins to grant super_admin.');
        }

        return self::SUCCESS;
    }
}
