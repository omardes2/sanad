<?php

declare(strict_types=1);

namespace App\Support\Rbac;

/**
 * The approved role → permission matrix (Phase C0). This is the source of
 * truth; `sanad:rbac:bootstrap` synchronises the database to it and reports
 * every difference before writing.
 *
 *  super_admin  every permission (and every Gate ability via Gate::before)
 *  operations   providers/models/routing, test connection, settings, persona,
 *               usage (no costs), plans, subscribers (view) — NO credentials
 *  finance      pricing, usage incl. costs, audit, providers (view),
 *               subscribers (view) — NO credentials
 *  support      subscribers (view/manage), usage (no costs) — NO credentials
 */
final class RoleMatrix
{
    /**
     * @return array<string, list<Permission>>
     */
    public static function definition(): array
    {
        return [
            Role::SuperAdmin->value => Permission::cases(),

            Role::Operations->value => [
                Permission::DashboardAccess,
                Permission::AiProvidersView,
                Permission::AiProvidersManage,
                Permission::AiModelsManage,
                Permission::AiRoutingManage,
                Permission::AiCredentialsTest,
                Permission::SettingsManage,
                Permission::PersonaManage,
                Permission::UsageView,
                Permission::PlansManage,
                Permission::SubscribersView,
            ],

            Role::Finance->value => [
                Permission::DashboardAccess,
                Permission::AiProvidersView,
                Permission::AiPricingView,
                Permission::AiPricingManage,
                Permission::UsageView,
                Permission::UsageViewCosts,
                Permission::AuditView,
                Permission::SubscribersView,
            ],

            Role::Support->value => [
                Permission::DashboardAccess,
                Permission::SubscribersView,
                Permission::SubscribersManage,
                Permission::UsageView,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function permissionsFor(Role $role): array
    {
        return array_map(
            static fn (Permission $permission): string => $permission->value,
            self::definition()[$role->value],
        );
    }

    public static function grants(Role $role, Permission $permission): bool
    {
        return in_array($permission->value, self::permissionsFor($role), true);
    }
}
