<?php

declare(strict_types=1);

namespace App\Services\Reminders;

use App\Enums\ChannelType;
use App\Enums\ReminderStatus;
use App\Exceptions\Tools\ToolDomainException;
use App\Models\Reminder;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The ONLY writer of `reminders` (Phase F3-V1).
 *
 * Scheduling a reminder is a LOCAL, reversible write: it inserts one row. What
 * eventually leaves the platform is the delivery, and that belongs to the
 * notification subsystem — which is why `reminder.create@2` is a `write` while
 * the frozen `@1` (declared `external_write` + approval) is not executable.
 *
 * OWNERSHIP AND CONTEXT ARE NEVER ARGUMENTS. The subscriber comes from the
 * invocation's trusted context, the timezone snapshot from that subscriber's
 * profile, and the channel from the conversation the message arrived on. No
 * tool schema declares any of them, so a model cannot choose whose reminder it
 * is, which timezone it is read in, or where it will be delivered.
 *
 * ONE-TIME ONLY. Recurrence has no representation in this schema and none is
 * invented here; the recurring-reminder model is its own phase.
 */
final class ReminderService
{
    /**
     * @param  array{title: string, remind_at: string}  $input  validated tool arguments
     * @return array{reminder_id: int, scheduled_for: string}
     *
     * @throws ToolDomainException
     */
    public function create(User $subscriber, array $input, ChannelType $channel, ?int $sourceMessageId = null): array
    {
        $remindAt = CarbonImmutable::parse($input['remind_at'], 'UTC');

        if ($remindAt->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
            throw ToolDomainException::rule('وقت التذكير يجب أن يكون في المستقبل.');
        }

        $reminder = Reminder::query()->create([
            'user_id' => $subscriber->getKey(),                 // from context
            'title' => $input['title'],
            'remind_at' => $remindAt,                           // stored UTC
            'timezone' => $subscriber->timezone,                // snapshot, not a model argument
            'channel' => $channel->value,                       // trusted conversation context
            'status' => ReminderStatus::Pending->value,
            'source_message_id' => $sourceMessageId,
        ]);

        return [
            'reminder_id' => (int) $reminder->getKey(),
            'scheduled_for' => $remindAt->format('Y-m-d\TH:i'),
        ];
    }

    /**
     * Cancel a reminder that has not gone out.
     *
     * The state rules follow what the product already means by each status —
     * `Reminder::scopeDue()` picks up `pending` and nothing else:
     *   pending    → cancelled
     *   cancelled  → idempotent success (no second mutation)
     *   processing → refused: it is being delivered right now
     *   sent       → refused: it already reached the subscriber
     *   failed     → refused: delivery was ATTEMPTED and failed. The row is
     *                already inert (never re-selected by `due()`), and
     *                overwriting it with `cancelled` would erase the record of
     *                the failure. If a retry policy ever makes `failed`
     *                deliverable again, cancelling it becomes meaningful and
     *                needs its own decision.
     *
     * @return array{reminder_id: int, cancelled_at: string}
     *
     * @throws ToolDomainException
     */
    public function cancel(User $subscriber, int $reminderId): array
    {
        $reminder = Reminder::query()
            ->where('user_id', $subscriber->getKey())
            ->whereKey($reminderId)
            ->lockForUpdate()
            ->first();

        if ($reminder === null) {
            throw ToolDomainException::notFound('التذكير');
        }

        if ($reminder->status === ReminderStatus::Cancelled) {
            return self::result($reminder->getKey(), CarbonImmutable::instance($reminder->updated_at)->utc());
        }

        if ($reminder->status !== ReminderStatus::Pending) {
            throw ToolDomainException::rule(match ($reminder->status) {
                ReminderStatus::Processing => 'التذكير قيد الإرسال الآن ولا يمكن إلغاؤه.',
                ReminderStatus::Sent => 'التذكير أُرسل بالفعل.',
                default => 'فشل إرسال التذكير سابقًا؛ لا يُلغى وسجلّ الفشل لا يُمحى.',
            });
        }

        $now = CarbonImmutable::now('UTC');
        $reminder->forceFill(['status' => ReminderStatus::Cancelled->value, 'updated_at' => $now])->save();

        return self::result($reminder->getKey(), $now);
    }

    /** @return array{reminder_id: int, cancelled_at: string} */
    private static function result(mixed $reminderId, CarbonImmutable $at): array
    {
        return ['reminder_id' => (int) $reminderId, 'cancelled_at' => $at->format('Y-m-d\TH:i')];
    }
}
