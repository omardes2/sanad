<?php

declare(strict_types=1);

namespace App\Support\Audit;

/**
 * Known audit action names (dot-namespaced `<area>.<event>`). Free-form
 * actions are allowed, but every action the platform itself emits is listed
 * here so the audit page and tests can rely on stable names.
 */
final class AuditActions
{
    public const RbacBootstrapApplied = 'rbac.bootstrap_applied';

    public const RbacRoleAssigned = 'rbac.role_assigned';

    public const RbacRoleRevoked = 'rbac.role_revoked';

    public const SettingsUpdated = 'settings.updated';

    public const SettingsReset = 'settings.reset';
}
