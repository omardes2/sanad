<?php

declare(strict_types=1);

use App\Enums\ReminderPattern;
use App\Enums\ReminderScheduleStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recurring reminders: a SCHEDULE is the definition, and every occurrence stays
 * an ordinary row in `reminders`.
 *
 * WHY OCCURRENCES ARE NOT A NEW TABLE. `messages.reminder_id` is UNIQUE — at
 * most one outbound message per `reminders` row — and `attempts`, `claim_token`,
 * `claimed_at`, `dispatched_at`, `sent_at` and `last_error` are all PER-DELIVERY
 * facts on that row. Reusing one row for many deliveries would give a whole
 * series one attempt counter, one claim token for occurrences running
 * concurrently, and one outbound message in total. Every guarantee the delivery
 * phase established would collapse at once. So an occurrence IS a reminder, and
 * the dispatcher, the sweeper, the delivery policy and the usage recorder need
 * no change whatsoever.
 *
 * WHY THE UNIQUE KEY IS THE NOMINAL LOCAL TIME, not the instant. `occurrence_key`
 * holds the wall-clock the subscriber asked for — `2026-09-11T09:00` — because
 * that is the thing that does not move. The UTC instant of "9am on the 11th"
 * changes when a zone changes its rules, and an instant-keyed unique index would
 * then let a timezone-database update manufacture a SECOND row for the same
 * occurrence. The nominal key also makes a spring-forward shift harmless: the
 * identity stays `02:30` even when the instant resolves to `03:30`.
 *
 * WHAT IS DELIBERATELY ABSENT: any delivery state on `reminder_schedules`. A
 * schedule is never sent, never claimed and never has attempts. And no column
 * anywhere holds a recurrence EXPRESSION — the pattern is a closed enum plus two
 * bounded operands, so nothing in the database can describe work the code cannot
 * enumerate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminder_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The message that asked for the series, for provenance.
            $table->foreignId('source_message_id')->nullable()->constrained('messages')->nullOnDelete();

            $table->string('title');
            // Where occurrences are delivered. Trusted conversation context at
            // creation — never a model argument, exactly as one-time reminders.
            $table->string('channel');

            /*
             * The zone is READ AT EVERY MATERIALISATION, not snapshotted and
             * forgotten. That is the whole DST correction: «كل يوم الساعة ٩»
             * means 09:00 wall-clock on both sides of a transition, which a
             * fixed UTC offset cannot express.
             */
            $table->string('timezone');

            $table->string('pattern', 16);
            // The nominal wall-clock time, as HH:MM. Stored as a string because
            // it is a LOCAL time-of-day and not an instant; a time column would
            // invite being compared against `now()`.
            $table->string('local_time', 5);
            // Weekly: ISO weekdays 1..7 as a sorted JSON list. Monthly: 1..31.
            $table->json('weekdays')->nullable();
            $table->unsignedTinyInteger('day_of_month')->nullable();

            // Local calendar dates — the subscriber's window, not instants.
            $table->date('starts_on');
            $table->date('ends_on')->nullable();

            $table->string('status', 16)->default(ReminderScheduleStatus::Active->value);
            $table->timestamp('terminated_at')->nullable();

            /*
             * An OPTIMISATION CURSOR and nothing more: where the last run got
             * to, so the next one need not re-walk the whole horizon. It is
             * never authority. Authority is the unique occurrence key plus the
             * schedule's status and version under a lock — so a cursor that is
             * stale, ahead, or left behind by a partially failed transaction can
             * never cause a skipped or a duplicated occurrence.
             */
            $table->date('materialised_through')->nullable();

            // Optimistic-concurrency fence for termination versus materialisation.
            $table->unsignedInteger('version')->default(1);

            $table->timestamps();

            // The materialiser's read: active schedules, oldest cursor first.
            $table->index(['status', 'materialised_through'], 'reminder_schedules_status_cursor_idx');
            // The list tool's read, and the per-subscriber active cap.
            $table->index(['user_id', 'status'], 'reminder_schedules_user_status_idx');
        });

        Schema::table('reminders', function (Blueprint $table) {
            // NULL for every one-time reminder, which is why they are untouched.
            $table->foreignId('reminder_schedule_id')
                ->nullable()
                ->after('source_message_id')
                ->constrained('reminder_schedules')
                ->nullOnDelete();

            // The nominal local wall-clock of this occurrence: `2026-09-11T09:00`.
            $table->string('occurrence_key', 20)->nullable()->after('reminder_schedule_id');
            // The same value as a comparable local datetime, for display and
            // for proving "same wall clock across a DST transition". NEVER
            // compared against now(); `remind_at` is the only scheduling column.
            $table->timestamp('occurrence_local_at')->nullable()->after('occurrence_key');

            /*
             * ONE ROW PER OCCURRENCE PER SCHEDULE — the authority for duplicate
             * safety. Two materialisers racing cannot both insert: the loser
             * takes this violation and treats it as success. Multiple NULLs are
             * allowed on PostgreSQL and SQLite alike, so one-time reminders are
             * entirely unconstrained by it.
             */
            $table->unique(['reminder_schedule_id', 'occurrence_key'], 'reminders_schedule_occurrence_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            $patterns = "'".implode("', '", ReminderPattern::values())."'";
            $states = "'".implode("', '", ReminderScheduleStatus::values())."'";

            // The vocabulary is a code table; the database keeps the row
            // coherent whatever writes it.
            DB::statement("ALTER TABLE reminder_schedules ADD CONSTRAINT reminder_schedules_pattern_check CHECK (pattern IN ({$patterns}))");
            DB::statement("ALTER TABLE reminder_schedules ADD CONSTRAINT reminder_schedules_status_check CHECK (status IN ({$states}))");
            // A terminated schedule always records when; an active one never does.
            DB::statement("ALTER TABLE reminder_schedules ADD CONSTRAINT reminder_schedules_terminated_check CHECK ((status = 'terminated') = (terminated_at IS NOT NULL))");
            // A local time-of-day, not prose.
            DB::statement("ALTER TABLE reminder_schedules ADD CONSTRAINT reminder_schedules_local_time_check CHECK (local_time ~ '^([01][0-9]|2[0-3]):[0-5][0-9]$')");
            // Monthly operands stay inside a real calendar month.
            DB::statement('ALTER TABLE reminder_schedules ADD CONSTRAINT reminder_schedules_day_of_month_check CHECK (day_of_month IS NULL OR (day_of_month >= 1 AND day_of_month <= 31))');
            // A window that ends before it starts is not a window.
            DB::statement('ALTER TABLE reminder_schedules ADD CONSTRAINT reminder_schedules_window_check CHECK (ends_on IS NULL OR ends_on >= starts_on)');
            // An occurrence belongs to a schedule, and a schedule occurrence has
            // a key: neither half of the identity may exist without the other.
            DB::statement('ALTER TABLE reminders ADD CONSTRAINT reminders_occurrence_identity_check CHECK ((reminder_schedule_id IS NULL) = (occurrence_key IS NULL))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE reminders DROP CONSTRAINT IF EXISTS reminders_occurrence_identity_check');

            foreach ([
                'reminder_schedules_pattern_check',
                'reminder_schedules_status_check',
                'reminder_schedules_terminated_check',
                'reminder_schedules_local_time_check',
                'reminder_schedules_day_of_month_check',
                'reminder_schedules_window_check',
            ] as $constraint) {
                DB::statement("ALTER TABLE reminder_schedules DROP CONSTRAINT IF EXISTS {$constraint}");
            }
        }

        Schema::table('reminders', function (Blueprint $table) {
            $table->dropUnique('reminders_schedule_occurrence_unique');
            $table->dropConstrainedForeignId('reminder_schedule_id');
            $table->dropColumn(['occurrence_key', 'occurrence_local_at']);
        });

        Schema::dropIfExists('reminder_schedules');
    }
};
