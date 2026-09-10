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

    /** Calculated financials: known cost, coverage, MRR snapshots (super_admin + finance). */
    case FinanceView = 'finance.view';
    /** CSV export of the calculated financial aggregates (super_admin + finance). */
    case FinanceExport = 'finance.export';
    /** Record manual payments / refunds and allocate them (super_admin + finance). Write-side only; finance.view never grants it. */
    case FinancePaymentsManage = 'finance.payments.manage';

    case FinanceReconcile = 'finance.reconcile';

    case FinanceFxManage = 'finance.fx.manage';

    case FinanceClosePeriod = 'finance.close_period';

    case PlansManage = 'plans.manage';
    case SubscribersView = 'subscribers.view';
    case SubscribersManage = 'subscribers.manage';

    case RbacManage = 'rbac.manage';

    /** The V1 launch readiness board (read-only). */
    case LaunchReadinessView = 'launch.readiness.view';

    /** Tool invocation history: what the model proposed and what the server did. */
    case ToolsInvocationsView = 'tools.invocations.view';

    /**
     * Tool consent state. Read-only: REVOKING a consent is authorised by
     * `subscribers.manage` (ToolCapability::ALLOWED_OPERATOR_PERMISSIONS), and
     * there is deliberately NO permission that grants consent — only the
     * subscriber can create it, never staff.
     */
    case ToolsConsentsView = 'tools.consents.view';

    /** Durable-memory OPERATIONAL METADATA. Never memory content — no permission grants that. */
    case MemoryOperationsView = 'memory.operations.view';

    /** Reminder rows and their schedule. */
    case RemindersView = 'reminders.view';

    /** Delivery internals: failure reason, claim/dispatch stamps, physical attempts. */
    case RemindersDeliveryView = 'reminders.delivery.view';

    /**
     * Conversation METADATA — who, which channel, how many messages, when.
     * Deliberately separate from `messages.content.view`: knowing that a
     * subscriber wrote in yesterday is a support question; reading what they
     * wrote is a different one, and least privilege means they are not the same
     * grant.
     */
    case ConversationsView = 'conversations.view';

    /** Message BODIES. Strictly narrower than `conversations.view`. */
    case MessagesContentView = 'messages.content.view';

    /** Subscriber tasks. */
    /**
     * Follow-up METADATA: which loops exist, their state, how much of the ask
     * budget is spent, when the next ask is due.
     *
     * It deliberately does NOT carry the subscriber-authored question text. The
     * metadata answers the operational questions ("why did Sanad ask twice?",
     * "why is this loop held?") without reading what the subscriber said, which is
     * the same split `conversations.view` and `messages.content.view` already draw.
     */
    case FollowUpsView = 'follow_ups.view';

    case TasksView = 'tasks.view';

    /** Operating expenses. */
    case ExpensesView = 'expenses.view';

    /** WhatsApp integration and queue health (presence booleans only). */
    case WhatsAppStatusView = 'whatsapp.status.view';

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
            self::FinanceView => 'عرض المالية (محسوبة)',
            self::FinanceExport => 'تصدير المالية (CSV)',
            self::FinancePaymentsManage => 'تسجيل المدفوعات والاستردادات وتخصيصها',
            self::FinanceReconcile => 'فواتير المزوّدين وتسوية التكلفة',
            self::FinanceFxManage => 'أسعار الصرف اليدوية وعملة التقرير',
            self::FinanceClosePeriod => 'إقفال الفترة المالية وإعادة فتحها (super_admin فقط)',
            self::PlansManage => 'إدارة الباقات',
            self::SubscribersView => 'عرض المشتركين',
            self::SubscribersManage => 'إدارة المشتركين',
            self::RbacManage => 'إدارة الأدوار والصلاحيات',
            self::LaunchReadinessView => 'عرض جاهزية الإطلاق V1',
            self::ToolsInvocationsView => 'عرض استدعاءات الأدوات',
            self::ToolsConsentsView => 'عرض موافقات الأدوات',
            self::MemoryOperationsView => 'عرض البيانات التشغيلية للذاكرة الدائمة',
            self::RemindersView => 'عرض التذكيرات',
            self::RemindersDeliveryView => 'عرض تفاصيل تسليم التذكيرات',
            self::ConversationsView => 'عرض بيانات المحادثات الوصفية',
            self::MessagesContentView => 'عرض محتوى الرسائل',
            self::FollowUpsView => 'عرض المتابعات (بيانات وصفية بلا نصّ سؤال المشترك)',
            self::TasksView => 'عرض المهام',
            self::ExpensesView => 'عرض المصروفات',
            self::WhatsAppStatusView => 'عرض حالة واتساب والطوابير',
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
