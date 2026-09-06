<?php

declare(strict_types=1);

namespace App\Support\Settings;

use App\Enums\SettingPrecedence;
use App\Enums\SettingType;
use App\Exceptions\Settings\UnknownSettingException;
use App\Support\Rbac\Permission;

/**
 * The code registry of every admin-editable setting (Phase C1): key, type,
 * default (a config path), validation, group, permission and precedence.
 * SettingsRepository refuses any key not defined here.
 *
 * Precedence per key (decision A): operational settings resolve DB > config
 * default and never consult the environment; only the emergency switches
 * resolve env override > DB > config default.
 */
final class SettingsRegistry
{
    public const GROUP_PERSONA = 'persona';

    public const GROUP_GENERATION = 'generation';

    public const GROUP_FAILURE = 'failure';

    public const GROUP_BILLING_MESSAGES = 'billing_messages';

    public const GROUP_SUBSCRIPTIONS = 'subscriptions';

    public const GROUP_GUARDRAILS = 'guardrails';

    public const GROUP_HEALTH = 'health';

    public const GROUP_EMERGENCY = 'emergency';

    public const GROUP_FINANCE = 'finance';

    public const REPORTING_CURRENCY = 'finance.reporting_currency';

    /** @var array<string, SettingDefinition>|null */
    private ?array $definitions = null;

    /**
     * @return array<string, SettingDefinition> keyed by setting key
     */
    public function all(): array
    {
        if ($this->definitions === null) {
            $this->definitions = [];

            foreach ($this->build() as $definition) {
                $this->definitions[$definition->key] = $definition;
            }
        }

        return $this->definitions;
    }

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    public function find(string $key): ?SettingDefinition
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * @throws UnknownSettingException
     */
    public function require(string $key): SettingDefinition
    {
        return $this->find($key) ?? throw UnknownSettingException::for($key);
    }

    /**
     * @return array<string, list<SettingDefinition>> group => definitions (registry order)
     */
    public function grouped(): array
    {
        $groups = [];

        foreach ($this->all() as $definition) {
            $groups[$definition->group][] = $definition;
        }

        return $groups;
    }

    /**
     * @return array<string, string> group => Arabic label
     */
    public static function groupLabels(): array
    {
        return [
            self::GROUP_PERSONA => 'الشخصية والـPrompts',
            self::GROUP_GENERATION => 'معاملات التوليد',
            self::GROUP_FAILURE => 'سلوك الفشل',
            self::GROUP_BILLING_MESSAGES => 'رسائل الفوترة',
            self::GROUP_SUBSCRIPTIONS => 'الاشتراكات',
            self::GROUP_GUARDRAILS => 'الحواجز المالية',
            self::GROUP_HEALTH => 'صحة المزوّدين',
            self::GROUP_EMERGENCY => 'مفاتيح الطوارئ',
            self::GROUP_FINANCE => 'المالية',
        ];
    }

    /**
     * @return list<SettingDefinition>
     */
    private function build(): array
    {
        return [
            // ---- Persona / prompts (persona.manage) ---------------------------
            new SettingDefinition(
                key: 'ai.persona',
                type: SettingType::Text,
                group: self::GROUP_PERSONA,
                label: 'شخصية سَنَد (System prompt)',
                description: 'النص الذي يعرّف شخصية المساعد ونبرته وأسلوبه. نص عادي بلا عناصر قابلة للتنفيذ.',
                permission: Permission::PersonaManage,
                precedence: SettingPrecedence::Operational,
                defaultConfigPath: 'ai.persona',
                rules: ['required', 'string', 'min:20', 'max:8000'],
            ),
            new SettingDefinition(
                key: 'prompts.temporal_context',
                type: SettingType::Template,
                group: self::GROUP_PERSONA,
                label: 'قالب سياق الوقت',
                description: 'يُضاف بعد الشخصية ليعرف المساعد التاريخ والوقت. العناصر المسموحة: {timezone} و{now}؛ {now} مطلوب.',
                permission: Permission::PersonaManage,
                precedence: SettingPrecedence::Operational,
                defaultConfigPath: 'ai.prompts.temporal_context',
                rules: ['required', 'string', 'max:1000'],
                placeholders: ['timezone', 'now'],
                requiredPlaceholders: ['now'],
            ),

            // ---- Generation parameters (settings.manage) ----------------------
            new SettingDefinition(
                key: 'ai.temperature',
                type: SettingType::Float,
                group: self::GROUP_GENERATION,
                label: 'Temperature',
                description: 'درجة العشوائية في الردود (0 = حتمي، 2 = أقصى تنوّع).',
                permission: Permission::SettingsManage,
                precedence: SettingPrecedence::Operational,
                defaultConfigPath: 'ai.temperature',
                rules: ['required', 'numeric', 'min:0', 'max:2'],
            ),
            new SettingDefinition(
                key: 'ai.max_output_tokens',
                type: SettingType::Integer,
                group: self::GROUP_GENERATION,
                label: 'الحد الأقصى لتوكنز الردّ',
                description: 'أقصى طول للردّ الذي يولّده النموذج.',
                permission: Permission::SettingsManage,
                precedence: SettingPrecedence::Operational,
                defaultConfigPath: 'ai.max_output_tokens',
                rules: ['required', 'integer', 'min:1', 'max:4096'],
            ),
            new SettingDefinition(
                key: 'ai.history_limit',
                type: SettingType::Integer,
                group: self::GROUP_GENERATION,
                label: 'عدد رسائل السياق',
                description: 'كم رسالة سابقة من المحادثة نفسها تُضمَّن في الـprompt.',
                permission: Permission::SettingsManage,
                precedence: SettingPrecedence::Operational,
                defaultConfigPath: 'ai.history_limit',
                rules: ['required', 'integer', 'min:1', 'max:50'],
            ),
            new SettingDefinition(
                key: 'ai.timeout',
                type: SettingType::Integer,
                group: self::GROUP_GENERATION,
                label: 'مهلة استدعاء المزوّد (ثوانٍ)',
                description: 'يجب أن تبقى أقل من مهلة عامل الطابور (60) وretry_after (90).',
                permission: Permission::SettingsManage,
                precedence: SettingPrecedence::Operational,
                defaultConfigPath: 'ai.timeout',
                rules: ['required', 'integer', 'min:5', 'max:45'],
            ),

            // ---- Failure behaviour (settings.manage) --------------------------
            new SettingDefinition(
                key: 'ai.failure_behavior',
                type: SettingType::Enum,
                group: self::GROUP_FAILURE,
                label: 'سلوك الفشل',
                description: 'retry: إعادة المحاولة عبر الطابور للأخطاء المؤقتة. reply: الردّ فورًا برسالة الاعتذار.',
                permission: Permission::SettingsManage,
                precedence: SettingPrecedence::Operational,
                defaultConfigPath: 'ai.failure_behavior',
                rules: ['required', 'string'],
                options: ['retry', 'reply'],
            ),
            new SettingDefinition(
                key: 'ai.fallback_message',
                type: SettingType::Text,
                group: self::GROUP_FAILURE,
                label: 'رسالة الاعتذار عند الفشل',
                description: 'تُرسل للمستخدم عندما يفشل المساعد نهائيًا.',
                permission: Permission::SettingsManage,
                precedence: SettingPrecedence::Operational,
                defaultConfigPath: 'ai.fallback_message',
                rules: ['required', 'string', 'min:5', 'max:1000'],
            ),

            // ---- Billing messages (settings.manage) ---------------------------
            new SettingDefinition(
                key: 'billing.limit_reached_message',
                type: SettingType::Template,
                group: self::GROUP_BILLING_MESSAGES,
                label: 'رسالة الوصول إلى الحدّ',
                description: 'العنصر المسموح: {upgrade} (رابط الترقية، اختياري).',
                permission: Permission::SettingsManage,
                precedence: SettingPrecedence::Operational,
                defaultConfigPath: 'billing.limit_reached_message',
                rules: ['required', 'string', 'min:5', 'max:1000'],
                placeholders: ['upgrade'],
            ),
            new SettingDefinition(
                key: 'billing.feature_disabled_message',
                type: SettingType::Text,
                group: self::GROUP_BILLING_MESSAGES,
                label: 'رسالة الميزة غير المتاحة',
                description: 'تُرسل عندما لا تشمل الباقة الميزة المطلوبة.',
                permission: Permission::SettingsManage,
                precedence: SettingPrecedence::Operational,
                defaultConfigPath: 'billing.feature_disabled_message',
                rules: ['required', 'string', 'min:5', 'max:1000'],
            ),
            new SettingDefinition(
                key: 'billing.upgrade_url',
                type: SettingType::String,
                group: self::GROUP_BILLING_MESSAGES,
                label: 'رابط الترقية',
                description: 'يُستبدل مكان {upgrade}. اتركه فارغًا إن لم يتوفر بعد.',
                permission: Permission::SettingsManage,
                precedence: SettingPrecedence::Operational,
                defaultConfigPath: 'billing.upgrade_url',
                rules: ['nullable', 'string', 'url', 'starts_with:https://', 'max:500'],
                nullable: true,
            ),

            // ---- Subscriptions (settings.manage_billing — super_admin) --------
            new SettingDefinition(
                key: 'billing.auto_trial',
                type: SettingType::Boolean,
                group: self::GROUP_SUBSCRIPTIONS,
                label: 'إسناد الباقة الافتراضية تلقائيًا',
                description: 'عند أول تواصل يُمنح المشترك الجديد الباقة الافتراضية.',
                permission: Permission::SettingsManageBilling,
                precedence: SettingPrecedence::Operational,
                defaultConfigPath: 'billing.auto_trial',
                rules: ['required', 'boolean'],
            ),
            new SettingDefinition(
                key: 'billing.default_plan_slug',
                type: SettingType::String,
                group: self::GROUP_SUBSCRIPTIONS,
                label: 'slug الباقة الافتراضية',
                description: 'الباقة التي تُسند تلقائيًا. إن لم تكن موجودة/مفعّلة تُستخدم الباقة المعلَّمة is_default.',
                permission: Permission::SettingsManageBilling,
                precedence: SettingPrecedence::Operational,
                defaultConfigPath: 'billing.default_plan_slug',
                rules: ['required', 'string', 'alpha_dash', 'max:64'],
            ),

            // ---- Financial guardrails (settings.manage_billing — super_admin) -
            new SettingDefinition(
                key: 'ai.guardrails.max_cost_per_request',
                type: SettingType::Float,
                group: self::GROUP_GUARDRAILS,
                label: 'الحد الأقصى للتكلفة المقدّرة لكل طلب',
                description: 'بعملة التكلفة. فارغ = بلا حاجز. النموذج ذو التقدير المعروف الأعلى من الحد يُتخطّى.',
                permission: Permission::SettingsManageBilling,
                precedence: SettingPrecedence::Operational,
                defaultConfigPath: 'ai.guardrails.max_cost_per_request',
                rules: ['nullable', 'numeric', 'min:0', 'max:10'],
                nullable: true,
            ),
            new SettingDefinition(
                key: 'ai.guardrails.estimate_input_tokens',
                type: SettingType::Integer,
                group: self::GROUP_GUARDRAILS,
                label: 'حجم المدخلات النموذجي للتقدير',
                description: 'عدد توكنز المدخلات المفترض عند تقدير تكلفة الطلب.',
                permission: Permission::SettingsManageBilling,
                precedence: SettingPrecedence::Operational,
                defaultConfigPath: 'ai.guardrails.estimate_input_tokens',
                rules: ['required', 'integer', 'min:1', 'max:100000'],
            ),
            new SettingDefinition(
                key: 'ai.guardrails.estimate_output_tokens',
                type: SettingType::Integer,
                group: self::GROUP_GUARDRAILS,
                label: 'حجم المخرجات النموذجي للتقدير',
                description: 'عدد توكنز المخرجات المفترض عند تقدير تكلفة الطلب.',
                permission: Permission::SettingsManageBilling,
                precedence: SettingPrecedence::Operational,
                defaultConfigPath: 'ai.guardrails.estimate_output_tokens',
                rules: ['required', 'integer', 'min:1', 'max:100000'],
            ),

            // ---- Provider health (settings.manage) ---------------------------
            new SettingDefinition(
                key: 'ai.health.scheduled',
                type: SettingType::Boolean,
                group: self::GROUP_HEALTH,
                label: 'فحوصات الصحة المجدولة',
                description: 'معطّل افتراضيًا. عند التفعيل يُشغَّل فحص المصادقة غير المفوتر فقط كل 15 دقيقة للمزوّدين الذين يعلنون دعمه. لا يُجدوَل أي استدلال مفوتر أبدًا.',
                permission: Permission::SettingsManage,
                precedence: SettingPrecedence::Operational,
                defaultConfigPath: 'ai.health.scheduled',
                rules: ['required', 'boolean'],
            ),

            // ---- Emergency switches (settings.manage_emergency — super_admin) -
            new SettingDefinition(
                key: 'ai.credentials_mode',
                type: SettingType::Enum,
                group: self::GROUP_EMERGENCY,
                label: 'مصدر مفاتيح المزوّدين',
                description: 'env = مفاتيح البيئة فقط (الرجوع الفوري). vault = مفتاح الخزنة الفعّال أولًا؛ مزوّد بلا مفتاح فعّال يعود إلى البيئة أثناء الانتقال؛ مفتاح فعّال لا يمكن فتحه ⇒ المزوّد يُغلق. AI_CREDENTIALS_MODE في البيئة يتقدّم على هذه القيمة.',
                permission: Permission::SettingsManageEmergency,
                precedence: SettingPrecedence::Emergency,
                defaultConfigPath: 'ai.credentials_mode',
                rules: ['required', 'string'],
                options: ['env', 'vault'],
                envKey: 'AI_CREDENTIALS_MODE',
                overrideConfigPath: 'ai.overrides.credentials_mode',
            ),
            new SettingDefinition(
                key: 'ai.enabled',
                type: SettingType::Boolean,
                group: self::GROUP_EMERGENCY,
                label: 'تفعيل الذكاء الاصطناعي',
                description: 'معطّل = يعمل المساعد الحتمي البديل بلا أي اتصال خارجي. AI_ENABLED في البيئة يتقدّم على هذه القيمة.',
                permission: Permission::SettingsManageEmergency,
                precedence: SettingPrecedence::Emergency,
                defaultConfigPath: 'ai.enabled',
                rules: ['required', 'boolean'],
                envKey: 'AI_ENABLED',
                overrideConfigPath: 'ai.overrides.enabled',
            ),
            new SettingDefinition(
                key: 'ai.catalog_source',
                type: SettingType::Enum,
                group: self::GROUP_EMERGENCY,
                label: 'مصدر كتالوج النماذج',
                description: 'auto / database / config. يُغيَّر فقط من صفحة Cutover (جاهزية + محاكاة + تأكيد مكتوب + Audit). AI_CATALOG_SOURCE في البيئة يتقدّم على هذه القيمة (مفتاح الرجوع الفوري).',
                permission: Permission::AiRoutingCutover,
                precedence: SettingPrecedence::Emergency,
                defaultConfigPath: 'ai.catalog_source',
                rules: ['required', 'string'],
                options: ['auto', 'database', 'config'],
                envKey: 'AI_CATALOG_SOURCE',
                overrideConfigPath: 'ai.overrides.catalog_source',
                managed: true,
            ),
            new SettingDefinition(
                key: 'ai.routing.mode',
                type: SettingType::Enum,
                group: self::GROUP_EMERGENCY,
                label: 'مصدر تفضيل التوجيه',
                description: 'env = AI_PROVIDER هو الحاكم (الرجوع الفوري). db = المزوّد الأساسي (is_primary) في قاعدة البيانات هو الحاكم. يُغيَّر فقط من صفحة Cutover. AI_ROUTING_MODE في البيئة يتقدّم على هذه القيمة.',
                permission: Permission::AiRoutingCutover,
                precedence: SettingPrecedence::Emergency,
                defaultConfigPath: 'ai.routing.mode',
                rules: ['required', 'string'],
                options: ['env', 'db'],
                envKey: 'AI_ROUTING_MODE',
                overrideConfigPath: 'ai.overrides.routing_mode',
                managed: true,
            ),
            new SettingDefinition(
                key: 'billing.enforce',
                type: SettingType::Boolean,
                group: self::GROUP_EMERGENCY,
                label: 'إنفاذ حدود الباقات',
                description: 'للعرض فقط في هذه المرحلة: يبقى محكومًا بـBILLING_ENFORCE في البيئة ولا يُعدَّل من اللوحة.',
                permission: Permission::SettingsManageEmergency,
                precedence: SettingPrecedence::Emergency,
                defaultConfigPath: 'billing.enforce',
                rules: ['required', 'boolean'],
                envKey: 'BILLING_ENFORCE',
                overrideConfigPath: 'billing.overrides.enforce',
                readOnly: true,
            ),
            // Phase E3 — reporting currency: written ONLY by ReportingCurrencyService
            // (finance.fx.manage + typed confirmation + audit). Changing it never
            // recomputes or rewrites any frozen conversion.
            new SettingDefinition(
                key: self::REPORTING_CURRENCY,
                type: SettingType::String,
                group: self::GROUP_FINANCE,
                label: 'عملة التقرير (Reporting currency)',
                description: 'العملة التي تُعرض بها القيم المحوَّلة للتقارير. الافتراضي عملة التكلفة. لا تحويل ضمني: يظهر فقط ما حُوِّل بسعر مسجَّل صراحة.',
                permission: Permission::FinanceFxManage,
                precedence: SettingPrecedence::Operational,
                defaultConfigPath: 'billing.cost_currency',
                rules: ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
                managed: true,
            ),
        ];
    }
}
