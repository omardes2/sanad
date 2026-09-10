<?php

declare(strict_types=1);

namespace App\Services\Reminders;

use App\Data\Reminders\PlannedOccurrence;
use App\Enums\ReminderScheduleStatus;
use App\Enums\ReminderStatus;
use App\Models\Reminder;
use App\Models\ReminderSchedule;
use App\Support\Reminders\OccurrencePlanner;
use App\Support\SafeError;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Creates the occurrence rows a schedule is due to produce — and nothing else.
 *
 * It does not deliver, does not claim, does not settle and does not cancel. Every
 * occurrence it writes is an ORDINARY reminder, so the dispatcher and the sweeper
 * pick it up with no knowledge that recurrence exists.
 *
 * ── WHAT IS AUTHORITY, AND WHAT IS MERELY AN OPTIMISATION ────────────────────
 * AUTHORITY is `UNIQUE (reminder_schedule_id, occurrence_key)` plus the
 * schedule's status and `version` re-read under a row lock. `materialised_through`
 * is a CURSOR and an optimisation only — it exists so a run need not re-walk the
 * whole horizon, and it is deliberately incapable of causing harm:
 *
 *   - it never lets an occurrence be SKIPPED, because the walk always restarts
 *     from "now" rather than from the cursor, and the cursor only ever narrows
 *     how far ahead the walk goes;
 *   - it is advanced in the SAME transaction as the inserts it describes, so a
 *     partially failed run leaves it behind rather than ahead;
 *   - and even a cursor that is wrong in either direction cannot duplicate an
 *     occurrence or resurrect a terminated series, because the unique key and the
 *     status/version re-read decide those, not the cursor.
 *
 * ── TERMINATION RACES MATERIALISATION ───────────────────────────────────────
 * A run that read a schedule as active a moment ago must be STRUCTURALLY unable
 * to insert after a cancellation commits. So the inserts happen inside a
 * transaction that re-reads the schedule `lockForUpdate()` and re-checks both its
 * status and the `version` it was planned from. A cancellation takes the same
 * lock and bumps that version, so one of the two waits for the other and the
 * loser finds the world changed and writes nothing. The scheduler's
 * `withoutOverlapping` guard plays no part in this argument.
 *
 * ── THE BOUNDS ───────────────────────────────────────────────────────────────
 * Both the day horizon and the per-schedule occurrence count apply, whichever
 * binds first. Reaching either is a reason to STOP CREATING and never to delete:
 * an occurrence that exists may already hold a claim or a delivery record, and
 * removing it to make room would destroy exactly the evidence this design keeps.
 */
final class ReminderMaterialiser
{
    public function __construct(private readonly OccurrencePlanner $planner) {}

    /**
     * Walk active schedules and create what is due.
     *
     * @return array{schedules: int, occurrences: int}
     */
    public function run(): array
    {
        if (! $this->enabled()) {
            return ['schedules' => 0, 'occurrences' => 0];
        }

        $schedules = ReminderSchedule::query()
            ->active()
            ->orderByRaw('materialised_through is null desc')
            ->orderBy('materialised_through')
            ->orderBy('id')
            ->limit(max(1, (int) config('reminders.recurrence.materialise_batch', 100)))
            ->get();

        $created = 0;
        $touched = 0;

        foreach ($schedules as $schedule) {
            try {
                $created += $this->materialise($schedule);
                $touched++;
            } catch (Throwable $e) {
                // One malformed schedule must not stop the others. It is reported
                // and skipped; nothing about it is guessed at or repaired here.
                Log::error('sanad.reminders.materialise_failed', [
                    'schedule_id' => $schedule->getKey(),
                    'error' => SafeError::summarize($e),
                ]);
            }
        }

        if ($created > 0) {
            Log::info('sanad.reminders.materialised', ['schedules' => $touched, 'occurrences' => $created]);
        }

        return ['schedules' => $touched, 'occurrences' => $created];
    }

    /**
     * Create the occurrences one schedule is due, respecting both bounds.
     *
     * @return int how many rows were created
     */
    public function materialise(ReminderSchedule $schedule): int
    {
        $now = CarbonImmutable::now('UTC');
        $cap = $this->cap();

        // How much room the count bound leaves. "Uncompleted" is the honest
        // measure: pending and processing rows are future work, while sent,
        // failed and cancelled ones are history and must not hold a slot.
        $room = max(0, $cap - $this->outstanding($schedule));

        if ($room < 1) {
            return 0;
        }

        $through = $this->horizonDate($schedule, $now);

        /*
         * Plan PAST what already exists, not just the free room.
         *
         * The planner always walks from now, so the occurrences it returns first
         * are the ones already materialised. Asking it for only `room` would hand
         * back `room` occurrences that all exist, create nothing, and leave the
         * series quietly refusing to extend — exactly what would happen once some
         * of its occurrences were delivered and freed their slots.
         *
         * So the window is "everything still ahead of now" plus the room: enough
         * for `room` genuinely new occurrences to appear at the end of it. The
         * insert below skips what exists and stops after `room` creations, so the
         * bound is still the bound.
         */
        $planned = $this->planner->plan($schedule, $now, $through, $this->ahead($schedule, $now) + $room);

        if ($planned === []) {
            // Nothing due inside the horizon. The cursor still advances, so the
            // next run need not re-walk the same empty window.
            $this->advanceCursor($schedule, $through);

            return 0;
        }

        return $this->insert($schedule, $planned, $through, $room);
    }

    /**
     * The guarded write: re-read the schedule under a lock, prove it is still the
     * same active definition that was planned from, then insert.
     *
     * @param  list<PlannedOccurrence>  $planned
     * @param  int  $room  how many rows the count bound still allows
     */
    private function insert(ReminderSchedule $schedule, array $planned, string $through, int $room): int
    {
        return (int) DB::transaction(function () use ($schedule, $planned, $through, $room): int {
            /** @var ReminderSchedule|null $fresh */
            $fresh = ReminderSchedule::query()->whereKey($schedule->getKey())->lockForUpdate()->first();

            if ($fresh === null
                || $fresh->status !== ReminderScheduleStatus::Active
                // The fence: a cancellation bumped the version while this run was
                // planning, so everything planned is from a world that no longer
                // exists. Nothing is written, and the next run re-plans.
                || $fresh->version !== $schedule->version) {
                return 0;
            }

            $created = 0;

            foreach ($planned as $occurrence) {
                if ($created >= $room) {
                    // The count bound is reached. Stop CREATING; never delete to
                    // make room, because an existing occurrence may already hold a
                    // claim or a delivery record.
                    break;
                }

                if ($this->create($fresh, $occurrence)) {
                    $created++;
                }
            }

            // Advanced in the SAME transaction as the inserts it describes, so it
            // can never run ahead of what was actually written.
            $fresh->forceFill(['materialised_through' => $through])->save();

            return $created;
        });
    }

    /**
     * One occurrence row, or nothing if it already exists.
     *
     * The unique key is the arbiter. A concurrent materialiser that inserted the
     * same occurrence first makes this a no-op — which is success, not an error:
     * the occurrence exists, which is all anyone asked for.
     *
     * `createOrFirst` and NOT `create()` inside a try/catch. This runs inside the
     * guarded transaction, and on PostgreSQL a unique violation ABORTS the
     * surrounding transaction — catching the exception would leave every later
     * statement failing with "current transaction is aborted", so one concurrent
     * materialiser would destroy the whole batch rather than skip one row.
     * `createOrFirst` wraps the insert in a SAVEPOINT when a transaction is open,
     * so the violation rolls back only that savepoint and the existing row comes
     * back. The match attributes are exactly the unique key.
     */
    private function create(ReminderSchedule $schedule, PlannedOccurrence $occurrence): bool
    {
        $reminder = Reminder::query()->createOrFirst(
            [
                'reminder_schedule_id' => $schedule->getKey(),
                'occurrence_key' => $occurrence->key,
            ],
            [
                'user_id' => $schedule->user_id,
                'occurrence_local_at' => $occurrence->localAt,
                'source_message_id' => $schedule->source_message_id,
                'title' => $schedule->title,
                'remind_at' => $occurrence->remindAt,
                // The zone this occurrence was RESOLVED in, snapshotted per row
                // exactly as a one-time reminder snapshots it.
                'timezone' => $schedule->timezone,
                'channel' => $schedule->channel->value,
                'status' => ReminderStatus::Pending->value,
            ],
        );

        return $reminder->wasRecentlyCreated;
    }

    private function cap(): int
    {
        return max(1, (int) config('reminders.recurrence.max_occurrences_per_schedule', 35));
    }

    /**
     * How many occurrence rows this schedule already has whose moment is still
     * ahead — i.e. exactly the ones the planner will walk over again.
     *
     * Settled or not: a row that exists occupies an occurrence key, so the walk
     * has to reach past it whether it was delivered, cancelled or is still
     * pending.
     */
    private function ahead(ReminderSchedule $schedule, CarbonImmutable $now): int
    {
        return Reminder::query()
            ->where('reminder_schedule_id', $schedule->getKey())
            ->where('remind_at', '>', $now)
            ->count();
    }

    /**
     * How many occurrences of this schedule are still FUTURE WORK.
     *
     * Pending and processing rows occupy the bound; sent, failed and cancelled
     * ones are history and must not. Counting history would mean a long-running
     * daily series eventually stopped scheduling itself — the bound is about how
     * much future Sanad holds, not how much past it remembers.
     */
    private function outstanding(ReminderSchedule $schedule): int
    {
        return Reminder::query()
            ->where('reminder_schedule_id', $schedule->getKey())
            ->whereIn('status', [ReminderStatus::Pending->value, ReminderStatus::Processing->value])
            ->count();
    }

    /** The last local date the horizon reaches, in the schedule's own zone. */
    private function horizonDate(ReminderSchedule $schedule, CarbonImmutable $now): string
    {
        $days = max(1, (int) config('reminders.recurrence.horizon_days', 30));

        $horizon = $now->copy()->setTimezone($schedule->timezone)->addDays($days)->format('Y-m-d');

        // A series with its own end date never plans past it.
        if ($schedule->ends_on !== null) {
            return min($horizon, $schedule->ends_on->format('Y-m-d'));
        }

        return $horizon;
    }

    /**
     * Move the cursor when nothing was created, under the same status/version
     * fence — so an empty run cannot quietly advance a terminated schedule.
     */
    private function advanceCursor(ReminderSchedule $schedule, string $through): void
    {
        DB::transaction(function () use ($schedule, $through): void {
            /** @var ReminderSchedule|null $fresh */
            $fresh = ReminderSchedule::query()->whereKey($schedule->getKey())->lockForUpdate()->first();

            if ($fresh === null
                || $fresh->status !== ReminderScheduleStatus::Active
                || $fresh->version !== $schedule->version) {
                return;
            }

            $fresh->forceFill(['materialised_through' => $through])->save();
        });
    }

    private function enabled(): bool
    {
        return (bool) config('reminders.enabled', true)
            && (bool) config('reminders.recurrence.enabled', true);
    }
}
