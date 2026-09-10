<?php

declare(strict_types=1);

namespace App\Services\Launch\Checks;

use App\Data\Launch\GateDetail;
use App\Data\Launch\GateOutcome;
use App\Services\Reminders\ReminderDeliveryPolicy;

/**
 * Follow-Up Until Done, and the external template it depends on.
 *
 * TWO GATES, NOT ONE, and the split is the whole point. Sanad's side of a
 * follow-up can be complete while Meta's side is not: a follow-up ask is a
 * QUESTION and templates are approved one at a time, so the reminder template
 * being live says nothing about whether Sanad may ask «دفعت الفاتورة؟». Reporting
 * both facts on one row would make it impossible to see which side is unfinished,
 * and a single green light would be a lie either way.
 *
 * So `reminders.follow_up` reports the implementation and its bounds, and
 * `follow_up.template` carries the external dependency — visible, and BLOCKING.
 *
 * What is deliberately absent from both: any rate, any ask-failure percentage,
 * any "loops blocked" threshold. No number has been approved as launch authority,
 * and one invented here would decide a release nobody agreed to (ADR-0046).
 */
final class FollowUpChecks
{
    public static function followUp(): GateOutcome
    {
        $enabled = (bool) config('follow_ups.enabled', true);
        $deliveryEnabled = (bool) config('reminders.enabled', true);

        $details = [
            GateDetail::boolean('المتابعة مفعَّلة', $enabled, 'مفعَّلة', 'معطّلة'),
            GateDetail::plain(
                'سلطة الإنشاء',
                'طلب صريح من المشترك + وقت حدَّده هو — بلا طلب صريح لا تُفتح متابعة، وبلا وقت يسأل سَنَد ولا يفترض',
            ),
            GateDetail::plain(
                'الحدود',
                sprintf(
                    '%d متابعة مفتوحة لكل مشترك · %d أسئلة كحدّ أقصى لكل متابعة · %d ساعة على الأقل بين سؤالين',
                    (int) config('follow_ups.max_open_per_subscriber', 5),
                    (int) config('follow_ups.max_asks_per_follow_up', 3),
                    (int) config('follow_ups.min_ask_interval_hours', 24),
                ),
            ),
            GateDetail::plain('الأمر المجدول', 'sanad:follow-ups:materialise — كل دقيقة، إنشاء وتقدُّم حالة فقط'),
            GateDetail::plain(
                'هوية السؤال',
                'كل سؤال صفّ تذكير مستقل بمطالبته وميزانية محاولاته ورسالته الصادرة — وسؤال واحد معلَّق كحدّ أقصى في كل وقت',
            ),
            GateDetail::plain(
                'ميزانية الأسئلة',
                'تُحسَب من حقيقة التذكير: السؤال الذي غادر المنصّة فعلًا (attempts > 0) — والمرفوض قبل الإرسال لا يستهلك شيئًا',
            ),
            GateDetail::plain(
                'الإغلاق',
                'جواب المشترك المرتبط بالسؤال، أو إنجاز المهمة المرتبطة، أو إلغاء المشترك، أو نفاد الميزانية — والصمت لا يُغلق شيئًا أبدًا',
            ),
            GateDetail::plain(
                'نافذة الجواب',
                (int) config('follow_ups.answer_window_hours', 72).' ساعة بعد السؤال، وبمتابعة واحدة فقط بانتظار الجواب — وإلا فلا ربط',
            ),
        ];

        if (! $enabled) {
            $details[] = GateDetail::plain('الأثر', 'لا تُفتح متابعة ولا يُولَّد سؤال؛ والتذكيرات غير متأثّرة');

            return GateOutcome::notReady('المتابعة حتى الإنجاز معطّلة في هذه البيئة.', $details);
        }

        if (! $deliveryEnabled) {
            // Asks that nothing would deliver.
            $details[] = GateDetail::bad('تسليم التذكيرات', 'معطّل — ستُولَّد أسئلة لا يُسلِّمها شيء');

            return GateOutcome::notReady('المتابعة مفعَّلة وتسليم التذكيرات معطّل.', $details);
        }

        // The external dependency, named here WITHOUT being counted as this gate's
        // blocker: it is `follow_up.template`'s, and it is already blocking there.
        $templateReady = app(ReminderDeliveryPolicy::class)->hasFollowUpTemplate();

        $details[] = $templateReady
            ? GateDetail::ok('قالب المتابعة', 'معتمَد ومضبوط — السؤال خارج نافذة الخدمة ممكن')
            : GateDetail::bad(
                'قالب المتابعة',
                'غير مضبوط: المتابعة التي يحين سؤالها خارج نافذة الخدمة تُحجَز في حالة «موقوفة تشغيليًا» بلا استهلاك ميزانية وبلا تسليم فاشل متكرِّر — وهو حاجز البند الخارجي لا حاجز المتابعة',
            );

        if (! $templateReady) {
            return GateOutcome::ready(
                'جانب سَنَد من المتابعة مكتمل؛ والسؤال خارج نافذة الخدمة يبقى محجوبًا ببند القالب الخارجي.',
                $details,
            );
        }

        return GateOutcome::ready('المتابعة حتى الإنجاز مفعَّلة، والسؤال ممكن داخل النافذة وخارجها.', $details);
    }

    /**
     * The approved FOLLOW-UP template — a separate external dependency from the
     * reminder template, because Meta approves templates one at a time and a
     * question is not a reminder.
     *
     * `ready` stays false and `name` stays empty until a real template is approved
     * and configured: no template name is invented in code.
     */
    public static function template(): GateOutcome
    {
        $ready = (bool) config('follow_ups.whatsapp.template.ready', false);
        $name = trim((string) config('follow_ups.whatsapp.template.name', ''));
        $language = trim((string) config('follow_ups.whatsapp.template.language', ''));

        $details = [
            GateDetail::boolean('علم الجاهزية', $ready, 'مضبوط', 'غير مضبوط'),
            // The template NAME is configuration, not a secret.
            GateDetail::boolean('اسم القالب', $name !== '', $name !== '' ? $name : 'غير مضبوط', 'غير مضبوط'),
            GateDetail::boolean('لغة القالب', $language !== '', $language !== '' ? $language : 'غير مضبوطة', 'غير مضبوطة'),
            GateDetail::plain(
                'لماذا قالب منفصل',
                'سؤال المتابعة («دفعت الفاتورة؟») ليس تذكيرًا، واعتماد Meta يكون لكل قالب على حدة — فاستعمال قالب التذكيرات يعني كلامًا غير معتمَد أمام المشترك',
            ),
            GateDetail::plain(
                'السلوك بلا قالب',
                'السؤال المستحقّ خارج نافذة الخدمة لا يُنشأ أصلًا: المتابعة تُحجَز «موقوفة تشغيليًا» بلا استهلاك ميزانية، والاستعادة أمر تشغيلي صريح (sanad:follow-ups:unblock)',
            ),
        ];

        if ($ready && $name !== '' && $language !== '') {
            return GateOutcome::ready('قالب المتابعة معتمَد ومضبوط.', $details);
        }

        return GateOutcome::blockedExternal(
            'لا يوجد قالب متابعة معتمَد ومضبوط: المتابعة خارج نافذة الخدمة تبقى موقوفة.',
            $details,
        );
    }
}
