<?php

declare(strict_types=1);

namespace App\Support\Rbac;

/**
 * The approved role → permission matrix (Phase C0). This is the source of
 * truth; `sanad:rbac:bootstrap` synchronises the database to it and reports
 * every difference before writing.
 *
 *  super_admin  every permission (and every Gate ability via Gate::before)
 *  operations   providers/models/routing, test connection, health view, settings (not the
 *               billing/subscription keys nor the emergency switches), persona,
 *               usage (no costs), plans, subscribers (view) — NO credentials.
 *               Plus the operational surfaces: launch readiness, tool invocations,
 *               tool consents, memory metadata, reminders incl. delivery internals,
 *               conversations, message content, tasks, WhatsApp status.
 *  finance      pricing, usage incl. costs and CSV export, finance (calculated
 *               financials + export), audit, providers (view), subscribers
 *               (view), expenses, launch readiness — NO credentials
 *  support      subscribers (view/manage), usage (no costs), tool consents (read),
 *               reminders (schedule only), conversation METADATA, tasks —
 *               NO credentials, NO message content, NO memory metadata,
 *               NO tool invocations, NO delivery internals
 *
 * LEAST PRIVILEGE, and two lines of it are deliberate rather than incidental:
 *
 *  - Support gets `conversations.view` but NOT `messages.content.view`. Support
 *    needs to know that a subscriber wrote in, on which channel, and when.
 *    Reading what they actually said is a different question, and answering it
 *    by default would hand every support account the contents of every private
 *    conversation on the platform.
 *  - NOBODY gets memory CONTENT, because no such permission exists. Support does
 *    not even get memory metadata: the counts are an engineering signal, not a
 *    support tool, and every row in that table is something a subscriber asked
 *    Sanad to remember about them.
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
                Permission::AiHealthView,
                Permission::SettingsManage,
                Permission::PersonaManage,
                Permission::UsageView,
                Permission::PlansManage,
                Permission::SubscribersView,
                Permission::LaunchReadinessView,
                Permission::ToolsInvocationsView,
                Permission::ToolsConsentsView,
                Permission::MemoryOperationsView,
                Permission::RemindersView,
                Permission::RemindersDeliveryView,
                Permission::ConversationsView,
                Permission::MessagesContentView,
                Permission::TasksView,
                Permission::WhatsAppStatusView,
            ],

            Role::Finance->value => [
                Permission::DashboardAccess,
                Permission::AiProvidersView,
                Permission::AiPricingView,
                Permission::AiPricingManage,
                Permission::UsageView,
                Permission::UsageViewCosts,
                Permission::UsageExport,
                Permission::FinanceView,
                Permission::FinanceExport,
                Permission::FinancePaymentsManage,
                Permission::FinanceReconcile,
                Permission::FinanceFxManage,
                Permission::AuditView,
                Permission::SubscribersView,
                Permission::ExpensesView,
                Permission::LaunchReadinessView,
            ],

            Role::Support->value => [
                Permission::DashboardAccess,
                Permission::SubscribersView,
                Permission::SubscribersManage,
                Permission::UsageView,
                // Consent state, because "why did Sanad refuse to remember that?"
                // is a support question. Revoking is separately authorised by
                // subscribers.manage, which Support does hold; granting is not a
                // permission at all — only the subscriber can create consent.
                Permission::ToolsConsentsView,
                // The reminder's schedule and status, so Support can answer
                // "did my reminder go out?" — WITHOUT the delivery internals.
                Permission::RemindersView,
                // Conversation metadata only. Message BODIES are deliberately
                // withheld: see the class docblock.
                Permission::ConversationsView,
                Permission::TasksView,
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
