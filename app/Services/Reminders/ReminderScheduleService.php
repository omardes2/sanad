<?php

declare(strict_types=1);

namespace App\Services\Reminders;

use App\Enums\ChannelType;
use App\Enums\ReminderCancelScope;
use App\Enums\ReminderPattern;
use App\Enums\ReminderScheduleStatus;
use App\Enums\ReminderStatus;
use App\Exceptions\Tools\ToolDomainException;
use App\Models\Reminder;
use App\Models\ReminderSchedule;
use App\Models\User;
use App\Support\Reminders\OccurrencePlanner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY writer of `reminder_schedules`, and the only place a series is
 * terminated.
 *
 * Creating a schedule is a LOCAL, reversible write: it inserts one definition
 * row. It sends nothing and creates no occurrence — materialisation does that,
 * separately and idempotently — which is why the tool is a `write` and not an
 * `external_write`, on exactly the reasoning that made `reminder.create@2`
 * replace the frozen `@1`.
 *
 * OWNERSHIP AND CONTEXT ARE NEVER ARGUMENTS, as for one-time reminders: the
 * subscriber comes from the invocation's trusted context, the timezone from that
 * subscriber's profile, the channel from the conversation the message arrived on.
 * No tool schema declares any of them, so a model cannot choose whose series it
 * is, which wall clock it is read in, or where it will be delivered.
 *
 * NO IN-PLACE EDIT. A changed recurrence is this one terminated and a new one
 * created, so every occurrence keeps a provenance trail to the definition that
 * produced it and no already-materialised occurrence is ever silently re-timed.
 */
final class ReminderScheduleService
{
    /**
     * The HARD ceiling on what one listing may return.
     *
     * The tool's output schema is declared in code at boot and must bound the
     * list; configuration can only narrow it from here, never widen it past what
     * the contract promised. Without this split a raised `list_limit` would make
     * the schema reject the service's own answer.
     */
    public const LIST_MAX = 25;

    public function __construct(private readonly OccurrencePlanner $planner) {}

    /**
     * Create one recurring series.
     *
     * @param  array{title: string, pattern: string, local_time: string, weekdays?: string|null, day_of_month?: int|null, starts_on?: string|null, ends_on?: string|null}  $input
     * @return array{schedule_id: int, pattern: string, recurrence: string, next_occurrence_local: string}
     *
     * @throws ToolDomainException
     */
    public function create(User $subscriber, array $input, ChannelType $channel, ?int $sourceMessageId = null): array
    {
        if (! (bool) config('reminders.recurrence.enabled', true)) {
            throw ToolDomainException::rule('التذكيرات المتكرِّرة غير متاحة حاليًا.');
        }

        $pattern = ReminderPattern::tryFrom((string) $input['pattern'])
            ?? throw ToolDomainException::rule('نمط التكرار غير مدعوم.');

        $timezone = $this->timezone($subscriber);
        $localTime = $this->localTime((string) $input['local_time']);
        $weekdays = $this->weekdays($pattern, $input['weekdays'] ?? null);
        $dayOfMonth = $this->dayOfMonth($pattern, $input['day_of_month'] ?? null);

        $today = CarbonImmutable::now('UTC')->setTimezone($timezone)->format('Y-m-d');
        $startsOn = $this->localDate($input['starts_on'] ?? null, $today, 'تاريخ البداية');
        $endsOn = isset($input['ends_on']) && $input['ends_on'] !== null && $input['ends_on'] !== ''
            ? $this->localDate($input['ends_on'], $today, 'تاريخ النهاية')
            : null;

        if ($startsOn < $today) {
            // A series that starts in the past would either back-fill or begin
            // with a silent gap. Neither is what the subscriber asked for.
            throw ToolDomainException::rule('تاريخ بداية التكرار يجب أن يكون اليوم أو بعده.');
        }

        if ($endsOn !== null && $endsOn < $startsOn) {
            throw ToolDomainException::rule('تاريخ نهاية التكرار يجب أن يكون بعد تاريخ البداية.');
        }

        /*
         * The per-subscriber cap is enforced INSIDE a transaction that first locks
         * the SUBSCRIBER'S OWN ROW.
         *
         * Locking the schedules and counting them would not be enough: `SELECT
         * … FOR UPDATE` cannot lock a row that does not exist yet, so two
         * concurrent creations at one below the cap would both count room and
         * both insert — the phantom the usage counters ran into for the same
         * reason. PostgreSQL also refuses `FOR UPDATE` with an aggregate
         * outright. Locking the one row both transactions must touch — the user —
         * serialises them on something real.
         *
         * At the cap a new schedule is REFUSED; nothing existing is terminated to
         * make space, because a series the subscriber set up is not the
         * platform's to discard.
         */
        return DB::transaction(function () use ($subscriber, $channel, $sourceMessageId, $input, $pattern, $timezone, $localTime, $weekdays, $dayOfMonth, $startsOn, $endsOn): array {
            $cap = max(1, (int) config('reminders.recurrence.max_active_schedules_per_subscriber', 10));

            // The serialisation point: every creation for this subscriber waits
            // here, so the count below cannot be stale by the time it is acted on.
            User::query()->whereKey($subscriber->getKey())->lockForUpdate()->first();

            $active = ReminderSchedule::query()
                ->where('user_id', $subscriber->getKey())
                ->active()
                ->count();

            if ($active >= $cap) {
                throw ToolDomainException::rule("وصلت الحدّ الأقصى للتذكيرات المتكرِّرة الفعّالة ({$cap}). ألغِ واحدًا قبل إضافة جديد.");
            }

            $schedule = ReminderSchedule::query()->create([
                'user_id' => $subscriber->getKey(),          // from context
                'source_message_id' => $sourceMessageId,
                'title' => (string) $input['title'],
                'channel' => $channel->value,                // trusted conversation context
                'timezone' => $timezone,                     // subscriber profile, not an argument
                'pattern' => $pattern->value,
                'local_time' => $localTime,
                'weekdays' => $weekdays,
                'day_of_month' => $dayOfMonth,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'status' => ReminderScheduleStatus::Active->value,
            ]);

            return [
                'schedule_id' => (int) $schedule->getKey(),
                'pattern' => $pattern->value,
                'recurrence' => $schedule->recurrenceSummary(),
                // The first moment this series will fire, in the subscriber's own
                // wall clock — the only form that means anything to them.
                'next_occurrence_local' => $this->firstLocalMoment($schedule),
            ];
        });
    }

    /**
     * The subscriber's own ACTIVE series, bounded, so the model can tell which
     * one «وقف تذكير الدوا اليومي» means days later.
     *
     * Read-only and subscriber-isolated by construction: the owner comes from the
     * invocation context and there is no argument that could widen it.
     *
     * @param  array{limit?: int|null, include_terminated?: bool|null}  $input
     * @return array{schedules: list<array<string, mixed>>, truncated: bool}
     */
    public function list(User $subscriber, array $input = []): array
    {
        $max = min(self::LIST_MAX, max(1, (int) config('reminders.recurrence.list_limit', 10)));
        $limit = isset($input['limit']) && (int) $input['limit'] > 0
            ? min((int) $input['limit'], $max)
            : $max;

        $query = ReminderSchedule::query()
            ->where('user_id', $subscriber->getKey())
            ->orderByDesc('id');

        // Active by default: a terminated series is not something the subscriber
        // can act on, and offering it invites cancelling what is already over.
        if (($input['include_terminated'] ?? false) !== true) {
            $query->active();
        }

        // One row more than the bound, so `truncated` is a fact rather than a
        // guess about whether anything was left out.
        $rows = $query->limit($limit + 1)->get();
        $truncated = $rows->count() > $limit;

        $schedules = $rows->take($limit)->map(fn (ReminderSchedule $schedule): array => [
            'schedule_id' => (int) $schedule->getKey(),
            // The subscriber's own words, which is the only way they will
            // recognise which series this is.
            'title' => (string) $schedule->title,
            'pattern' => $schedule->pattern->value,
            'recurrence' => $schedule->recurrenceSummary(),
            'status' => $schedule->status->value,
            'next_occurrence_local' => $this->nextLocalMoment($schedule),
        ])->values()->all();

        return ['schedules' => $schedules, 'truncated' => $truncated];
    }

    /**
     * Stop a series.
     *
     * TERMINATION AND CANCELLATION ARE ONE TRANSACTION. If the schedule were
     * terminated and the occurrences cancelled separately, a crash between the
     * two would leave a dead definition with live occurrences still queued to
     * fire — the subscriber told Sanad to stop and Sanad would keep going.
     *
     * `version` is incremented under the row lock and is what fences a
     * materialiser that read this schedule as active a moment ago: it re-reads
     * and re-checks inside its own locked transaction, so it cannot insert an
     * occurrence after this commit. The scheduler's overlap guard is not part of
     * that argument and correctness does not depend on it.
     *
     * @return array{schedule_id: int, scope: string, cancelled_occurrences: int, terminated: bool}
     *
     * @throws ToolDomainException
     */
    public function cancel(User $subscriber, int $scheduleId, ReminderCancelScope $scope): array
    {
        return DB::transaction(function () use ($subscriber, $scheduleId, $scope): array {
            /** @var ReminderSchedule|null $schedule */
            $schedule = ReminderSchedule::query()
                ->whereKey($scheduleId)
                ->where('user_id', $subscriber->getKey())   // ownership from context
                ->lockForUpdate()
                ->first();

            if ($schedule === null) {
                throw ToolDomainException::rule('لا يوجد تذكير متكرِّر بهذا المعرّف.');
            }

            $now = CarbonImmutable::now('UTC');
            $alreadyTerminated = ! $schedule->isActive();

            if (! $alreadyTerminated) {
                $schedule->forceFill([
                    'status' => ReminderScheduleStatus::Terminated->value,
                    'terminated_at' => $now,
                    'version' => $schedule->version + 1,
                ])->save();
            }

            /*
             * Only PENDING occurrences are cancellable. One being delivered right
             * now is not a schedule's to retract — the claim owns it — and a sent
             * or failed one is a record, not a plan.
             */
            $cancellable = Reminder::query()
                ->where('reminder_schedule_id', $schedule->getKey())
                ->where('status', ReminderStatus::Pending->value);

            if (! $scope->includesDue()) {
                // `future` spares an occurrence whose time has come and which no
                // worker has claimed yet: «بطّل من بكرا» is not «بطّل هلّق».
                $cancellable->where('remind_at', '>', $now);
            }

            $cancelled = $cancellable->update([
                'status' => ReminderStatus::Cancelled->value,
                'updated_at' => $now,
            ]);

            return [
                'schedule_id' => (int) $schedule->getKey(),
                'scope' => $scope->value,
                'cancelled_occurrences' => $cancelled,
                // Honest about an idempotent repeat: the series is stopped, and
                // this call is not the one that stopped it.
                'terminated' => ! $alreadyTerminated,
            ];
        });
    }

    /** The subscriber's profile zone, validated — never a tool argument. */
    private function timezone(User $subscriber): string
    {
        $timezone = trim((string) ($subscriber->timezone ?? ''));

        if ($timezone === '' || ! in_array($timezone, timezone_identifiers_list(), true)) {
            // Fail rather than guess: a recurring series read in the wrong zone
            // fires at the wrong hour forever, and silently.
            throw ToolDomainException::rule('المنطقة الزمنية للمشترك غير مضبوطة، فلا يمكن جدولة تكرار.');
        }

        return $timezone;
    }

    private function localTime(string $value): string
    {
        if (preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', trim($value)) !== 1) {
            throw ToolDomainException::rule('وقت التكرار يجب أن يكون بصيغة HH:MM.');
        }

        return trim($value);
    }

    /**
     * Weekly weekdays as a sorted, de-duplicated list of ISO days.
     *
     * Accepted as a bounded STRING (`1,4`) because a tool schema's scalar types
     * are closed and a list of integers is not one of them — the parsing is here,
     * in the domain, where a malformed value becomes a stated refusal.
     *
     * @return list<int>|null
     */
    private function weekdays(ReminderPattern $pattern, mixed $raw): ?array
    {
        if (! $pattern->needsWeekdays()) {
            if ($raw !== null && $raw !== '') {
                throw ToolDomainException::rule('أيام الأسبوع تُحدَّد للتكرار الأسبوعي فقط.');
            }

            return null;
        }

        $parts = array_filter(array_map('trim', explode(',', (string) $raw)), static fn (string $p): bool => $p !== '');
        $days = [];

        foreach ($parts as $part) {
            if (preg_match('/^[1-7]$/', $part) !== 1) {
                throw ToolDomainException::rule('أيام الأسبوع تُكتب أرقامًا من ١ (الاثنين) إلى ٧ (الأحد) مفصولة بفواصل.');
            }

            $days[] = (int) $part;
        }

        $days = array_values(array_unique($days));
        sort($days);

        if ($days === []) {
            throw ToolDomainException::rule('التكرار الأسبوعي يحتاج يومًا واحدًا على الأقل.');
        }

        return $days;
    }

    private function dayOfMonth(ReminderPattern $pattern, mixed $raw): ?int
    {
        if (! $pattern->needsDayOfMonth()) {
            if ($raw !== null && $raw !== '') {
                throw ToolDomainException::rule('يوم الشهر يُحدَّد للتكرار الشهري فقط.');
            }

            return null;
        }

        $day = (int) $raw;

        if ($day < 1 || $day > 31) {
            throw ToolDomainException::rule('يوم الشهر يجب أن يكون بين ١ و٣١.');
        }

        return $day;
    }

    private function localDate(mixed $raw, string $today, string $label): string
    {
        $value = trim((string) ($raw ?? ''));

        if ($value === '') {
            return $today;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw ToolDomainException::rule("{$label} يجب أن يكون بصيغة YYYY-MM-DD.");
        }

        return $value;
    }

    /** The first wall-clock moment a brand-new series will fire. */
    private function firstLocalMoment(ReminderSchedule $schedule): string
    {
        return $this->nextLocalMoment($schedule) ?? '—';
    }

    /**
     * The next wall-clock moment this series is due, from the planner — so what
     * the subscriber is told is computed by the same code that will materialise
     * it, never by a second, divergent formula.
     */
    private function nextLocalMoment(ReminderSchedule $schedule): ?string
    {
        if (! $schedule->isActive()) {
            return null;
        }

        $now = CarbonImmutable::now('UTC');
        $horizon = max(1, (int) config('reminders.recurrence.horizon_days', 30));
        $through = $now->copy()->setTimezone($schedule->timezone)->addDays($horizon)->format('Y-m-d');

        $planned = $this->planner->plan($schedule, $now, $through, 1);

        return $planned === [] ? null : substr($planned[0]->localAt, 0, 16);
    }
}
