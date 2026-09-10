<?php

declare(strict_types=1);

namespace App\Services\Launch;

use App\Enums\ReminderFailureReason;
use App\Enums\ReminderStatus;
use App\Enums\ToolInvocationStatus;
use App\Models\Reminder;
use App\Models\ToolInvocation;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Things an operator should look at — deliberately SEPARATE from launch gates.
 *
 * Nothing here can block a launch, and that separation is the point. A rate of
 * `too_late` failures, a spike in refusals, a deep queue: every one of those is
 * worth someone's attention and NONE of them is a release decision, because
 * turning one into a gate means picking a threshold, and no threshold has been
 * approved as launch authority. An unapproved number that silently decides
 * whether the product ships is worse than no number at all.
 *
 * So these are counts with a window and a link. They are reported, not judged.
 * If a threshold is ever wanted, it gets approved and moves to the registry as a
 * real gate — it does not sneak in here as an `if`.
 */
final class OperationalWarnings
{
    /** The window every count below is taken over. */
    public const WINDOW_DAYS = 7;

    /**
     * @return list<array{key: string, label: string, count: int, hint: string, route: ?string, params: array<string, string>}>
     */
    public function all(): array
    {
        $since = CarbonImmutable::now()->subDays(self::WINDOW_DAYS);

        return array_values(array_filter([
            $this->reminderFailure($since, ReminderFailureReason::TemplateRequired, 'تذكيرات فشلت: القالب غير متاح', 'رسالة استباقية خارج نافذة الخدمة بلا قالب معتمَد — فشل مغلق بلا إرسال.'),
            $this->reminderFailure($since, ReminderFailureReason::TooLate, 'تذكيرات فشلت: فات الأوان', 'تجاوز التذكير أقصى تأخّر مقبول، فالمناسبة انقضت.'),
            $this->reminderFailure($since, ReminderFailureReason::AttemptsExhausted, 'تذكيرات فشلت: نفدت المحاولات', 'محاولتان فعليّتان كحدّ أقصى لكل مناسبة، ثم يستقرّ الصف فاشلًا.'),
            $this->reminderFailure($since, ReminderFailureReason::Unknown, 'تذكيرات بنتيجة غير معروفة', 'انقطعت المعرفة بين الإرسال والتسوية — لا تُحسب فشلًا مؤكَّدًا ولا تكلفة صفرًا.'),
            $this->stuckReminders(),
            $this->invocations($since, ToolInvocationStatus::TimedOut, 'استدعاءات أدوات انتهت مهلتها', 'تجاوز التنفيذ المهلة المعلَنة للأداة.'),
            $this->invocations($since, ToolInvocationStatus::Failed, 'استدعاءات أدوات فشلت', 'فشل داخل الأداة نفسها بعد اجتياز كل البوابات.'),
            $this->invocations($since, ToolInvocationStatus::Refused, 'استدعاءات أدوات مرفوضة', 'رفض قبل التنفيذ: موافقة ناقصة، أو طلب غير صريح، أو مدخل غير صالح.'),
        ], static fn (?array $row): bool => $row !== null));
    }

    /**
     * @return array{key: string, label: string, count: int, hint: string, route: ?string, params: array<string, string>}|null
     */
    private function reminderFailure(CarbonImmutable $since, ReminderFailureReason $reason, string $label, string $hint): ?array
    {
        try {
            $count = Reminder::query()
                ->where('status', ReminderStatus::Failed->value)
                ->where('last_error', $reason->value)
                ->where('updated_at', '>=', $since)
                ->count();
        } catch (Throwable) {
            return null;
        }

        return $count === 0 ? null : [
            'key' => 'reminders.'.$reason->value,
            'label' => $label,
            'count' => $count,
            'hint' => $hint,
            'route' => 'dashboard.reminders',
            'params' => ['status' => ReminderStatus::Failed->value, 'reason' => $reason->value],
        ];
    }

    /**
     * Claimed but never settled past its lease — the shape a crashed worker
     * leaves behind. Reported, never counted as a launch blocker.
     *
     * @return array{key: string, label: string, count: int, hint: string, route: ?string, params: array<string, string>}|null
     */
    private function stuckReminders(): ?array
    {
        $lease = max(60, (int) config('reminders.lease_seconds', 300));
        $cutoff = CarbonImmutable::now()->subSeconds($lease * 2);

        try {
            $count = Reminder::query()
                ->where('status', ReminderStatus::Processing->value)
                ->where('claimed_at', '<', $cutoff)
                ->count();
        } catch (Throwable) {
            return null;
        }

        return $count === 0 ? null : [
            'key' => 'reminders.stuck',
            'label' => 'تذكيرات عالقة في المعالجة',
            'count' => $count,
            'hint' => 'محجوزة وتجاوزت ضعف مهلة الحجز بلا تسوية — الكانِس يستردّها في جولته التالية.',
            'route' => 'dashboard.reminders',
            'params' => ['status' => ReminderStatus::Processing->value],
        ];
    }

    /**
     * @return array{key: string, label: string, count: int, hint: string, route: ?string, params: array<string, string>}|null
     */
    private function invocations(CarbonImmutable $since, ToolInvocationStatus $status, string $label, string $hint): ?array
    {
        try {
            $count = ToolInvocation::query()
                ->where('status', $status->value)
                ->where('created_at', '>=', $since)
                ->count();
        } catch (Throwable) {
            return null;
        }

        return $count === 0 ? null : [
            'key' => 'tools.'.$status->value,
            'label' => $label,
            'count' => $count,
            'hint' => $hint,
            'route' => 'dashboard.tools.invocations',
            'params' => ['status' => $status->value],
        ];
    }
}
