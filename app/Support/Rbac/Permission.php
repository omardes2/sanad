<?php

declare(strict_types=1);

namespace App\Support\Rbac;

/**
 * Every permission the platform knows, as code (the registry). Roles are
 * granted sets of these (see RoleMatrix); the database rows are synchronised
 * FROM this enum by `sanad:rbac:bootstrap`, never edited by hand. Adding a
 * permission = adding a case here, then re-running the bootstrap.
 *
 * Naming: `<area>.<capability>`. `manage` implies the ability to change;
 * `view` is read-only. `usage.view_costs` separates money columns from
 * operational usage so Support never sees costs.
 */
enum Permission: string
{
    case DashboardAccess = 'dashboard.access';

    case AiProvidersView = 'ai.providers.view';
    case AiProvidersManage = 'ai.providers.manage';
    case AiModelsManage = 'ai.models.manage';
    case AiPricingView = 'ai.pricing.view';
    case AiPricingManage = 'ai.pricing.manage';
    case AiRoutingManage = 'ai.routing.manage';
    /** Catalog-source / routing-mode / primary cutovers (super_admin only). */
    case AiRoutingCutover = 'ai.routing.cutover';
    case AiCredentialsManage = 'ai.credentials.manage';
    case AiCredentialsTest = 'ai.credentials.test';
    /** Provider health history (super_admin + operations). */
    case AiHealthView = 'ai.health.view';

    case SettingsManage = 'settings.manage';
    /** Billing/subscription behaviour and financial guardrails (super_admin only in C1). */
    case SettingsManageBilling = 'settings.manage_billing';
    /** Database value of the emergency switches (super_admin only; env still wins). */
    case SettingsManageEmergency = 'settings.manage_emergency';
    case PersonaManage = 'persona.manage';

    case UsageView = 'usage.view';
    case UsageViewCosts = 'usage.view_costs';
    /** Streaming CSV export of the ledger (super_admin + finance only). */
    case UsageExport = 'usage.export';

    case AuditView = 'audit.view';

    case PlansManage = 'plans.manage';
    case SubscribersView = 'subscribers.view';
    case SubscribersManage = 'subscribers.manage';

    case RbacManage = 'rbac.manage';

    public function label(): string
    {
        return match ($this) {
            self::DashboardAccess => 'الدخول إلى اللوحة',
            self::AiProvidersView => 'عرض مزوّدي الذكاء الاصطناعي',
            self::AiProvidersManage => 'إدارة مزوّدي الذكاء الاصطناعي',
            self::AiModelsManage => 'إدارة النماذج',
            self::AiPricingView => 'عرض الأسعار',
            self::AiPricingManage => 'نشر الأسعار',
            self::AiRoutingManage => 'إدارة التوجيه',
            self::AiRoutingCutover => 'تنفيذ Cutover التوجيه والكتالوج',
            self::AiCredentialsManage => 'إدارة مفاتيح المزوّدين',
            self::AiCredentialsTest => 'اختبار الاتصال بالمزوّدين',
            self::AiHealthView => 'عرض صحة المزوّدين',
            self::SettingsManage => 'إدارة الإعدادات',
            self::SettingsManageBilling => 'إدارة إعدادات الفوترة والاشتراكات',
            self::SettingsManageEmergency => 'إدارة مفاتيح الطوارئ',
            self::PersonaManage => 'إدارة شخصية سَنَد والـPrompts',
            self::UsageView => 'عرض الاستخدام',
            self::UsageViewCosts => 'عرض التكاليف',
            self::UsageExport => 'تصدير الاستخدام (CSV)',
            self::AuditView => 'عرض سجل التدقيق',
            self::PlansManage => 'إدارة الباقات',
            self::SubscribersView => 'عرض المشتركين',
            self::SubscribersManage => 'إدارة المشتركين',
            self::RbacManage => 'إدارة الأدوار والصلاحيات',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $permission): string => $permission->value, self::cases());
    }
}
