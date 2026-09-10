<?php

declare(strict_types=1);

namespace App\Services\Launch\Checks;

use App\Data\Launch\GateDetail;
use App\Data\Launch\GateOutcome;
use App\Models\Reminder;
use App\Support\WhatsApp\WhatsAppConfig;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Reminder scheduling, delivery and the WhatsApp template dependency.
 *
 * TWO RULES SHAPE THIS FILE.
 *
 * 1. Scheduler state is decided by CONFIGURATION, never by silence. "No reminder
 *    was delivered recently" is not proof the cron is broken — it is equally
 *    consistent with nothing having been due. So the three questions are kept
 *    apart and reported separately: is the scheduler CONFIGURED, has it ever been
 *    OBSERVED to execute, and when was the LAST activity. Only the first can
 *    make this gate `not_ready`; the other two can only distinguish `ready` from
 *    `not_observed`, and `not_observed` never blocks a launch.
 *
 * 2. Only STRUCTURAL facts are launch gates. Delivery disabled, a channel that
 *    cannot send, a template that does not exist — those are gates. A rate of
 *    `too_late` or `template_required` failures is an OPERATIONAL WARNING and
 *    lives in `OperationalWarnings`, because no numerical threshold has been
 *    approved as launch authority and inventing one here would make a number
 *    nobody agreed to into a release decision.
 */
final class ReminderChecks
{
    public static function scheduler(): GateOutcome
    {
        $enabled = (bool) config('reminders.enabled', true);

        $details = [
            GateDetail::boolean('إعداد المُجدوِل', $enabled, 'مفعَّل (reminders.enabled)', 'معطّل (reminders.enabled=false)'),
            GateDetail::plain('الأوامر المجدولة', 'sanad:reminders:dispatch · sanad:reminders:sweep — كل دقيقة'),
            GateDetail::plain('دفعة الجولة', (string) (int) config('reminders.batch', 100)),
            GateDetail::plain('مهلة الحجز', (int) config('reminders.lease_seconds', 300).' ثانية'),
        ];

        if (! $enabled) {
            $details[] = GateDetail::plain('الأثر', 'لا يُحجَز أي تذكير ولا يُرسَل شيء');

            return GateOutcome::notReady('تسليم التذكيرات معطّل بالإعداد، فلا يعمل المُجدوِل أصلًا.', $details);
        }

        [$everExecuted, $lastActivity] = self::activity();

        if ($everExecuted === null) {
            $details[] = GateDetail::unknown('تنفيذ مرصود', 'تعذّرت القراءة');

            return GateOutcome::notObserved('الإعداد سليم؛ تعذّر التحقّق من التنفيذ.', $details);
        }

        $details[] = $everExecuted
            ? GateDetail::ok('تنفيذ مرصود', 'نعم — حُجِز تذكير واحد على الأقل فعليًا')
            : GateDetail::unknown('تنفيذ مرصود', 'لا يوجد دليل بعد');

        $details[] = $lastActivity !== null
            ? GateDetail::plain('آخر نشاط مرصود', $lastActivity->format('Y-m-d H:i').' UTC')
            : GateDetail::plain('آخر نشاط مرصود', 'لا يوجد');

        if (! $everExecuted) {
            // Deliberately NOT a blocker: no reminder may ever have been due.
            $details[] = GateDetail::plain(
                'ملاحظة',
                'غياب النشاط ليس دليلًا على عطل — قد لا يكون أي تذكير قد استحقّ بعد',
            );

            return GateOutcome::notObserved('المُجدوِل مضبوط، ولم يُرصد تنفيذ بعد.', $details);
        }

        return GateOutcome::ready('المُجدوِل مضبوط، وتنفيذه مرصود على صفوف حقيقية.', $details);
    }

    /**
     * RECURRENCE — implemented, so this gate REPORTS rather than declares.
     *
     * What it asks is whether a recurring reminder can actually happen end to
     * end: the machinery is switched on, a series can be created, occurrences are
     * materialised, and each occurrence then travels the ORDINARY delivery path —
     * so the delivery gate and the template gate above still carry their own
     * halves of the answer and this one does not restate them.
     *
     * WHAT IT DOES NOT DO is conflate recurrence with the external template
     * dependency. A recurring reminder fires, by nature, long after the
     * subscriber's last message and therefore almost always OUTSIDE the WhatsApp
     * free-form window, where the permitted mechanism is an approved template.
     * Without one, occurrences fail closed with `template_required`. That is a
     * real and serious constraint — it is stated on this row in plain words — but
     * it is the TEMPLATE gate's blocker, not recurrence's: Sanad's side can be
     * complete while Meta's side is not, and collapsing the two would make it
     * impossible to see which one is actually missing.
     *
     * Materialisation LAG is deliberately absent as a blocker. It is a rate over
     * a window, no threshold has been approved as launch authority, and a number
     * invented here would decide a release nobody agreed to.
     */
    public static function recurrence(): GateOutcome
    {
        $deliveryEnabled = (bool) config('reminders.enabled', true);
        $enabled = (bool) config('reminders.recurrence.enabled', true);

        $details = [
            GateDetail::boolean('التكرار مفعَّل', $enabled, 'مفعَّل', 'معطّل'),
            GateDetail::plain('الأنماط المدعومة', 'يومي · أسبوعي (أيام محدَّدة) · شهري (يوم محدَّد، مع تثبيت آخر الشهر)'),
            GateDetail::plain(
                'الحدود',
                sprintf(
                    'أفق %d يومًا · %d مرّة غير منتهية لكل سلسلة · %d سلسلة فعّالة لكل مشترك',
                    (int) config('reminders.recurrence.horizon_days', 30),
                    (int) config('reminders.recurrence.max_occurrences_per_schedule', 35),
                    (int) config('reminders.recurrence.max_active_schedules_per_subscriber', 10),
                ),
            ),
            GateDetail::plain('الأمر المجدول', 'sanad:reminders:materialise — كل دقيقة، إنشاء فقط'),
            GateDetail::plain(
                'هوية المرّة',
                'كل مرّة صفّ تذكير مستقل بمطالبة وميزانية محاولات ورسالة صادرة خاصة به — لا يُعاد استخدام صفّ لعدّة تسليمات',
            ),
            GateDetail::plain(
                'التوقيت',
                'الوقت المحلي يُحسَب من قواعد المنطقة عند كل مرّة: الفجوة الربيعية تُزاح بمقدارها الفعلي، والتكرار الخَرفي يختار الأولى',
            ),
            GateDetail::plain('التعديل', 'لا تعديل في V1 — التغيير إلغاءُ السلسلة وإنشاء غيرها'),
        ];

        if (! $enabled) {
            $details[] = GateDetail::plain('الأثر', 'لا تُنشأ سلسلة ولا تُولَّد مرّات؛ والتذكيرات المفردة غير متأثّرة');

            return GateOutcome::notReady('التذكيرات المتكرِّرة معطّلة في هذه البيئة.', $details);
        }

        if (! $deliveryEnabled) {
            // Recurrence without delivery would create occurrences nothing sends.
            $details[] = GateDetail::bad('تسليم التذكيرات', 'معطّل — ستُولَّد مرّات لا يُسلِّمها شيء');

            return GateOutcome::notReady('التكرار مفعَّل وتسليم التذكيرات معطّل.', $details);
        }

        // The external dependency, named on the row WITHOUT being counted as this
        // gate's blocker — it is the template gate's, and it is already blocking
        // there. Recurrence makes it the dominant one, so silence would mislead.
        $templateReady = (bool) config('reminders.whatsapp.template.ready', false)
            && trim((string) config('reminders.whatsapp.template.name', '')) !== '';

        $details[] = $templateReady
            ? GateDetail::ok('قالب واتساب', 'معتمَد ومضبوط — التسليم خارج النافذة ممكن')
            : GateDetail::bad(
                'قالب واتساب',
                'غير مضبوط: المرّة التي تحين خارج نافذة الخدمة تفشل مغلقة بسبب template_required — وهو حاجز البند الخارجي لا حاجز التكرار',
            );

        if (! $templateReady) {
            return GateOutcome::ready(
                'جانب سَنَد من التكرار مكتمل؛ والتسليم خارج نافذة الخدمة يبقى محجوبًا ببند القالب الخارجي.',
                $details,
            );
        }

        return GateOutcome::ready('التذكيرات المتكرِّرة مفعَّلة، والتسليم ممكن داخل النافذة وخارجها.', $details);
    }

    public static function delivery(): GateOutcome
    {
        $enabled = (bool) config('reminders.enabled', true);
        $whatsapp = app(WhatsAppConfig::class);
        $canSend = $whatsapp->canSend();

        $details = [
            GateDetail::boolean('التسليم مفعَّل', $enabled, 'مفعَّل', 'معطّل'),
            GateDetail::boolean('قناة واتساب قادرة على الإرسال', $canSend, 'قادرة', 'غير قادرة'),
            GateDetail::plain('أقصى تأخّر مقبول', (int) config('reminders.max_lateness_minutes', 60).' دقيقة'),
            GateDetail::plain('الضمان', 'تسليم مرة على الأقل ومحدود — محاولتان فعليّتان كحدّ أقصى لكل مناسبة'),
        ];

        if (! $enabled) {
            return GateOutcome::notReady('تسليم التذكيرات معطّل بالإعداد.', $details);
        }

        if (! $canSend) {
            return GateOutcome::notReady('لا توجد قناة قادرة على إرسال التذكيرات.', $details);
        }

        return GateOutcome::ready('مسار التسليم مضبوط وقناته قادرة على الإرسال.', $details);
    }

    /**
     * The approved-template dependency. `ready` stays false and `name` stays
     * empty until a real template is approved and configured — no template name
     * is invented in code, so this gate reports exactly what is configured.
     */
    public static function template(): GateOutcome
    {
        $ready = (bool) config('reminders.whatsapp.template.ready', false);
        $name = trim((string) config('reminders.whatsapp.template.name', ''));
        $language = trim((string) config('reminders.whatsapp.template.language', ''));

        $details = [
            GateDetail::boolean('علم الجاهزية', $ready, 'مضبوط', 'غير مضبوط'),
            // The template NAME is configuration, not a secret.
            GateDetail::boolean('اسم القالب', $name !== '', $name !== '' ? $name : 'غير مضبوط', 'غير مضبوط'),
            GateDetail::boolean('لغة القالب', $language !== '', $language !== '' ? $language : 'غير مضبوطة', 'غير مضبوطة'),
            GateDetail::plain('نافذة الرسالة الحرّة', (int) config('reminders.whatsapp.free_form_window_hours', 24).' ساعة'),
            GateDetail::plain('السلوك بلا قالب', 'فشل مغلق بسبب template_required — بلا طلب خارجي وبلا إعادة محاولة'),
        ];

        if ($ready && $name !== '' && $language !== '') {
            return GateOutcome::ready('قالب التذكيرات معتمَد ومضبوط.', $details);
        }

        return GateOutcome::blockedExternal(
            'لا يوجد قالب واتساب معتمَد ومضبوط: التذكيرات خارج نافذة الخدمة تفشل مغلقة.',
            $details,
        );
    }

    /**
     * Two aggregates over `reminders`, an operational table that stays small
     * relative to messages and invocations, read on demand by one admin page.
     *
     * @return array{0: ?bool, 1: ?CarbonImmutable} everExecuted (null = unreadable), lastActivity
     */
    private static function activity(): array
    {
        try {
            $lastClaimed = Reminder::query()->max('claimed_at');
            $lastDispatched = Reminder::query()->max('dispatched_at');
        } catch (Throwable) {
            return [null, null];
        }

        $stamps = array_values(array_filter(
            [$lastClaimed, $lastDispatched],
            static fn ($value): bool => $value !== null && $value !== '',
        ));

        if ($stamps === []) {
            return [false, null];
        }

        $latest = null;

        foreach ($stamps as $stamp) {
            try {
                $moment = CarbonImmutable::parse((string) $stamp);
            } catch (Throwable) {
                continue;
            }

            if ($latest === null || $moment->greaterThan($latest)) {
                $latest = $moment;
            }
        }

        return [true, $latest];
    }
}
