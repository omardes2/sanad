<?php

declare(strict_types=1);

namespace App\Support\Launch;

use App\Enums\LaunchGateOwner;
use App\Services\Launch\Checks\AiChecks;
use App\Services\Launch\Checks\FeatureChecks;
use App\Services\Launch\Checks\MemoryChecks;
use App\Services\Launch\Checks\PaymentChecks;
use App\Services\Launch\Checks\PlatformChecks;
use App\Services\Launch\Checks\ReminderChecks;
use App\Services\Launch\Checks\WhatsAppChecks;
use InvalidArgumentException;

/**
 * THE V1 LAUNCH GATES, DECLARED IN CODE.
 *
 * This registry — not a Markdown file — is the runtime authority for what V1
 * requires. `docs/SANAD_V1_LAUNCH_SCOPE.md` mirrors it for humans, and a test
 * asserts the two agree, but the document is never parsed and never decides
 * anything: prose drifts, gets translated, gets reformatted, and a release
 * decision that depends on a heading surviving a text edit is not a decision.
 *
 * Every gate names its CHECK as a `[class, method]` pair resolved right here,
 * exactly as `ToolIntentRequirements` resolves its verifiers. A gate therefore
 * cannot name an arbitrary class, callable, URL or command, and no row, payload
 * or document can introduce one.
 *
 * `requiredForV1` is a separate axis from the state a check returns. A gate that
 * is not required still appears — deferred work should be visible as deferred,
 * not absent — but it can never make the launch look blocked.
 */
final class LaunchGateRegistry
{
    /**
     * Ordered as the readiness screen reads them: the product path first, then
     * the features V1 still owes, then the platform and the money.
     *
     * @var list<array{key: string, title: string, owner: LaunchGateOwner, required: bool, why: string, check: array{0: class-string, 1: string}}>
     */
    private const GATES = [
        [
            'key' => 'ai.provider',
            'title' => 'مزوّد الذكاء الاصطناعي',
            'owner' => LaunchGateOwner::Operations,
            'required' => true,
            'why' => 'بلا مزوّد مفعَّل ومفتاح صالح لا يوجد رد ذكي إطلاقًا.',
            'check' => [AiChecks::class, 'provider'],
        ],
        [
            'key' => 'whatsapp',
            'title' => 'واتساب',
            'owner' => LaunchGateOwner::Operations,
            'required' => true,
            'why' => 'القناة الوحيدة التي يصل عبرها المشترك إلى سَنَد في V1.',
            'check' => [WhatsAppChecks::class, 'messaging'],
        ],
        [
            'key' => 'provider.tool_calling',
            'title' => 'استدعاء الأدوات عند المزوّد',
            'owner' => LaunchGateOwner::Sanad,
            'required' => true,
            'why' => 'المهام والتذكيرات والذاكرة كلها تمرّ عبر اقتراح النموذج وقرار الخادم.',
            'check' => [AiChecks::class, 'toolCalling'],
        ],
        [
            'key' => 'voice.transcription',
            'title' => 'الرسائل الصوتية والتفريغ',
            'owner' => LaunchGateOwner::Sanad,
            'required' => true,
            'why' => 'نطاق V1 يشترط أن يرسل المستخدم رسالة صوتية ويحصل على فهم ورد.',
            'check' => [FeatureChecks::class, 'voiceTranscription'],
        ],
        [
            'key' => 'reminders.recurring',
            'title' => 'التذكيرات المتكرِّرة',
            'owner' => LaunchGateOwner::Sanad,
            'required' => true,
            'why' => 'نطاق V1 يشترط تذكيرات متكرِّرة، ولا يوجد تمثيل للتكرار في المستودع.',
            'check' => [FeatureChecks::class, 'recurringReminders'],
        ],
        [
            'key' => 'reminders.follow_up',
            'title' => 'المتابعة حتى الإنجاز',
            'owner' => LaunchGateOwner::Sanad,
            'required' => true,
            'why' => 'نطاق V1 يشترط متابعة تعمل بلا إزعاج.',
            'check' => [FeatureChecks::class, 'followUpUntilDone'],
        ],
        [
            'key' => 'brief.morning',
            'title' => 'الموجز الصباحي',
            'owner' => LaunchGateOwner::Sanad,
            'required' => true,
            'why' => 'نطاق V1 يشترط وصول الموجز حسب توقيت المشترك المفعِّل له.',
            'check' => [FeatureChecks::class, 'morningBrief'],
        ],
        [
            'key' => 'reminders.scheduler',
            'title' => 'مُجدوِل التذكيرات',
            'owner' => LaunchGateOwner::Operations,
            'required' => true,
            'why' => 'التذكير الذي لا يُحجَز لا يصل.',
            'check' => [ReminderChecks::class, 'scheduler'],
        ],
        [
            'key' => 'reminders.delivery',
            'title' => 'تسليم التذكيرات',
            'owner' => LaunchGateOwner::Operations,
            'required' => true,
            'why' => 'نطاق V1 يشترط وصول التذكير في وقته الصحيح.',
            'check' => [ReminderChecks::class, 'delivery'],
        ],
        [
            'key' => 'reminders.template',
            'title' => 'قالب واتساب للتذكيرات',
            'owner' => LaunchGateOwner::Meta,
            'required' => true,
            'why' => 'التذكير رسالة استباقية وقد تحين بعد إغلاق نافذة الخدمة، وعندها المسموح قالب معتمَد فقط.',
            'check' => [ReminderChecks::class, 'template'],
        ],
        [
            'key' => 'memory.encryption',
            'title' => 'تشفير الذاكرة',
            'owner' => LaunchGateOwner::Operations,
            'required' => true,
            'why' => 'الذاكرة فاشلة مغلقة بلا مفتاحيها، ولا يجوز أن تدّعي V1 أن سَنَد «يعرف المشترك» قبل ضبطهما.',
            'check' => [MemoryChecks::class, 'encryption'],
        ],
        [
            'key' => 'memory.system',
            'title' => 'منظومة الذاكرة الدائمة',
            'owner' => LaunchGateOwner::Sanad,
            'required' => true,
            'why' => 'نطاق V1 يشترط أن يتذكّر سَنَد معلومة ويعود لاستخدامها لاحقًا.',
            'check' => [MemoryChecks::class, 'system'],
        ],
        [
            'key' => 'tools.rate_limiting',
            'title' => 'حدود المعدّل والحماية من الإساءة',
            'owner' => LaunchGateOwner::Sanad,
            'required' => true,
            'why' => 'منتج عام بلا أي حدّ مفروض مكشوف للإساءة، والقيمة المعلَنة اليوم ليست فرضًا.',
            'check' => [AiChecks::class, 'rateLimiting'],
        ],
        [
            'key' => 'infra.queue',
            'title' => 'عامل الطابور',
            'owner' => LaunchGateOwner::Operations,
            'required' => true,
            'why' => 'كل رسالة واردة وكل تسليم يمرّ عبر الطابور.',
            'check' => [PlatformChecks::class, 'queueWorker'],
        ],
        [
            'key' => 'infra.scheduler_cron',
            'title' => 'Cron المُجدوِل',
            'owner' => LaunchGateOwner::Operations,
            'required' => true,
            'why' => 'بلا schedule:run لا يعمل أي أمر مجدول.',
            'check' => [PlatformChecks::class, 'schedulerCron'],
        ],
        [
            'key' => 'payments.cybersource',
            'title' => 'الدفع عبر بوابة البنك (CyberSource)',
            'owner' => LaunchGateOwner::Bank,
            'required' => true,
            'why' => 'نطاق V1 يشترط أن يدفع المستخدم فعليًا عبر بوابة البنك.',
            'check' => [PaymentChecks::class, 'cybersource'],
        ],
        [
            'key' => 'billing.enforcement',
            'title' => 'فرض الحصص والفوترة',
            'owner' => LaunchGateOwner::Operations,
            'required' => true,
            'why' => 'سَنَد V1 منتج قائم على الاشتراك: قياس الحصص بلا فرضها مقبول في التطوير والاختبار، ولا يُعدّ جاهزًا للإطلاق. تبقى `BILLING_ENFORCE=false` في التطوير، ويبقى البند **حاجزًا** حتى تُفعِّل تهيئة الإنتاج/البيتا الفرضَ صراحةً.',
            'check' => [PlatformChecks::class, 'billingEnforcement'],
        ],

        // ---- Declared, and NOT required for V1 (deferred on purpose) -------
        [
            'key' => 'memory.implicit_extraction',
            'title' => 'الاستخراج الضمني للذاكرة',
            'owner' => LaunchGateOwner::Sanad,
            'required' => false,
            'why' => 'مؤجَّل بعد V1 عن قصد: V1 تحفظ بطلب صريح فقط.',
            'check' => [FeatureChecks::class, 'implicitMemoryExtraction'],
        ],
        [
            'key' => 'memory.semantic_retrieval',
            'title' => 'الاسترجاع الدلالي للذاكرة',
            'owner' => LaunchGateOwner::Sanad,
            'required' => false,
            'why' => 'مؤجَّل بعد V1 عن قصد: الترتيب الحتمي يكفي داخل سقف 50 ذاكرة.',
            'check' => [FeatureChecks::class, 'semanticMemoryRetrieval'],
        ],
    ];

    /**
     * @return list<LaunchGate>
     */
    public function all(): array
    {
        return array_map(
            static fn (array $gate): LaunchGate => LaunchGate::of(
                key: $gate['key'],
                title: $gate['title'],
                owner: $gate['owner'],
                requiredForV1: $gate['required'],
                why: $gate['why'],
                check: $gate['check'],
            ),
            self::GATES,
        );
    }

    /**
     * @return list<LaunchGate>
     */
    public function requiredForV1(): array
    {
        return array_values(array_filter($this->all(), static fn (LaunchGate $gate): bool => $gate->requiredForV1));
    }

    public function find(string $key): LaunchGate
    {
        foreach ($this->all() as $gate) {
            if ($gate->key === $key) {
                return $gate;
            }
        }

        throw new InvalidArgumentException("Unknown launch gate [{$key}].");
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_map(static fn (array $gate): string => $gate['key'], self::GATES);
    }
}
