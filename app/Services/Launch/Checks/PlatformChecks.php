<?php

declare(strict_types=1);

namespace App\Services\Launch\Checks;

use App\Data\Launch\GateDetail;
use App\Data\Launch\GateOutcome;
use App\Models\ProviderHealthCheck;
use App\Models\Reminder;
use App\Services\Platform\InfrastructureHealth;
use App\Services\Settings\SettingsRepository;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Deployment-shaped gates: the queue worker, the scheduler's cron, and whether
 * billing is actually enforced.
 *
 * The cron gate is the one that has to be careful. Nothing in the schema records
 * that `schedule:run` fired — there is no heartbeat row, and this phase does not
 * invent one. So liveness is inferred from OBSERVABLE EFFECTS of scheduled work,
 * and when none exist the answer is `not_observed`, which never blocks a launch.
 * A cron gate that turned silence into a red light would be wrong far more often
 * than it was right, and operators would learn to ignore the whole screen.
 */
final class PlatformChecks
{
    /** How recent an effect has to be before it counts as evidence the cron is alive. */
    private const CRON_EVIDENCE_HOURS = 24;

    public static function queueWorker(): GateOutcome
    {
        $health = app(InfrastructureHealth::class);

        $reachable = $health->queueReachable();
        $horizon = $health->horizon();
        $depths = $health->queueDepths();

        $details = [
            GateDetail::boolean('خلفية الطابور (Redis)', $reachable, 'متاحة', 'غير متاحة'),
            match ($horizon) {
                'running' => GateDetail::ok('Horizon', 'يعمل'),
                'inactive' => GateDetail::bad('Horizon', 'غير نشط'),
                default => GateDetail::unknown('Horizon', 'تعذّر الاستعلام'),
            },
        ];

        foreach ($depths as $queue => $depth) {
            $details[] = GateDetail::plain('طول الطابور: '.$queue, $depth === null ? '—' : (string) $depth);
        }

        if (! $reachable) {
            // Structural: the queue backend is genuinely unreachable.
            return GateOutcome::notReady('خلفية الطابور غير متاحة: لا شيء يُعالَج.', $details);
        }

        if ($horizon === 'inactive') {
            return GateOutcome::notReady('لا يوجد عامل طابور يعمل.', $details);
        }

        if ($horizon === 'unavailable') {
            // We could not ask Horizon — that is not the same as "it is down".
            return GateOutcome::notObserved('الطابور متاح؛ تعذّر التحقّق من حالة العامل.', $details);
        }

        return GateOutcome::ready('الطابور متاح وعامله يعمل.', $details);
    }

    public static function schedulerCron(): GateOutcome
    {
        $details = [
            GateDetail::plain('سطر الـcron المطلوب', '* * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1'),
            GateDetail::plain('الأوامر المجدولة', 'sanad:reminders:dispatch · sanad:reminders:sweep · sanad:ai:health:run · sanad:ai:health:prune'),
        ];

        $since = CarbonImmutable::now()->subHours(self::CRON_EVIDENCE_HOURS);
        $evidence = self::cronEvidence($since);

        if ($evidence === null) {
            $details[] = GateDetail::unknown('دليل التنفيذ', 'تعذّرت القراءة');

            return GateOutcome::notObserved('تعذّر التحقّق من تنفيذ المُجدوِل.', $details);
        }

        if ($evidence !== []) {
            foreach ($evidence as $label => $when) {
                $details[] = GateDetail::ok('دليل تنفيذ: '.$label, $when);
            }

            return GateOutcome::ready('رُصد أثر لعمل مجدول خلال آخر '.self::CRON_EVIDENCE_HOURS.' ساعة.', $details);
        }

        $details[] = GateDetail::unknown('دليل التنفيذ', 'لا يوجد أثر خلال آخر '.self::CRON_EVIDENCE_HOURS.' ساعة');
        $details[] = GateDetail::plain(
            'ملاحظة',
            'لا يوجد سجل نبض دائم في المخطط، وغياب الأثر ليس دليلًا على تعطّل الـcron — قد لا يكون هناك عمل مستحق',
        );
        $details[] = GateDetail::plain('ما الذي يثبته', 'أول تذكير يُحجَز أو يُرسَل، أو أول فحص صحة مجدول');

        // Deliberately NOT `not_ready`: see the class docblock.
        return GateOutcome::notObserved('لم يُرصد أثر تنفيذ مجدول؛ هذا ليس إثباتًا لعطل.', $details);
    }

    /**
     * Billing enforcement, reported as its EFFECTIVE state.
     *
     * The effective rule is `ai.enabled && billing.enforce`, and reporting only
     * half of it would mislead: quota is enforced only when the metered
     * orchestrator is the one actually bound, and `AppServiceProvider` binds it
     * only while AI is enabled. `BILLING_ENFORCE=true` with AI off enforces
     * nothing at all.
     *
     * Sanad V1 is a SUBSCRIPTION product, so this gate is required for V1:
     * measuring quotas without enforcing them is fine in development and is not
     * launch-ready. Development stays `BILLING_ENFORCE=false` and simply reads
     * as blocking on the board until production/beta configuration turns
     * enforcement on — the gate reports the truth either way rather than being
     * excused for being a development default.
     */
    public static function billingEnforcement(): GateOutcome
    {
        $enforce = (bool) config('billing.enforce', false);
        $aiEnabled = (bool) app(SettingsRepository::class)->get('ai.enabled');
        $effective = $enforce && $aiEnabled;

        $details = [
            GateDetail::boolean('الحالة الفعلية (ai.enabled && billing.enforce)', $effective, 'مفروض', 'غير مفروض'),
            GateDetail::boolean('billing.enforce', $enforce, 'مفعَّل', 'معطّل'),
            GateDetail::boolean('الذكاء الاصطناعي مفعَّل', $aiEnabled, 'مفعَّل', 'معطّل'),
            GateDetail::plain(
                'كيف يُفرَض',
                'MeteredAgentOrchestrator يفحص الحصة قبل نداء المزوّد ويخصمها بعده — ولا يُربَط أصلًا إلا حين يكون الذكاء مفعَّلًا',
            ),
        ];

        if ($effective) {
            return GateOutcome::ready('فرض الحصص فعّال.', $details);
        }

        $details[] = GateDetail::plain(
            'الأثر',
            $aiEnabled
                ? 'الحصص محسوبة وغير مفروضة: لا يُرفض أي رد لتجاوز الحدّ'
                : 'المساعد الحتمي البديل يعمل بلا قياس ولا فرض',
        );
        $details[] = GateDetail::plain(
            'ملاحظة',
            'ترك الفرض معطّلًا في التطوير مقبول؛ ويبقى هذا البند حاجزًا حتى تُفعِّله تهيئة الإنتاج/البيتا صراحةً',
        );

        return GateOutcome::notReady('فرض الحصص غير فعّال — وسَنَد V1 منتج اشتراك.', $details);
    }

    /**
     * Effects that only a scheduled run could have produced.
     *
     * @return array<string, string>|null label => timestamp; [] = none; null = unreadable
     */
    private static function cronEvidence(CarbonImmutable $since): ?array
    {
        $found = [];

        try {
            $claimed = Reminder::query()->where('claimed_at', '>=', $since)->max('claimed_at');

            if ($claimed !== null && $claimed !== '') {
                $found['حجز تذكير'] = CarbonImmutable::parse((string) $claimed)->format('Y-m-d H:i');
            }
        } catch (Throwable) {
            return null;
        }

        try {
            $health = ProviderHealthCheck::query()
                ->where('trigger', 'scheduled')
                ->where('checked_at', '>=', $since)
                ->max('checked_at');

            if ($health !== null && $health !== '') {
                $found['فحص صحة مجدول'] = CarbonImmutable::parse((string) $health)->format('Y-m-d H:i');
            }
        } catch (Throwable) {
            // The health table may be unreadable while reminders are fine; the
            // reminder evidence above still stands on its own.
        }

        return $found;
    }
}
