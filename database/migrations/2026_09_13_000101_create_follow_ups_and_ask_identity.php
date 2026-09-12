<?php

declare(strict_types=1);

use App\Enums\FollowUpBlockReason;
use App\Enums\FollowUpStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FOLLOW-UP UNTIL DONE — the loop, and the identity of one ask.
 *
 * ── WHY THE LOOP NEEDS ITS OWN ROW ───────────────────────────────────────────
 * `reminders` columns are facts about ONE DELIVERY: `attempts`, `claim_token`,
 * `dispatched_at`, `sent_at`, `last_error`, and a UNIQUE `messages.reminder_id`.
 * "Is this loop resolved, and how many times have we asked?" is a fact about the
 * LOOP, and no per-delivery row can hold it — the same argument that made a
 * recurring occurrence its own reminder instead of a counter on a schedule.
 *
 * ── WHY AN ASK IS STILL AN ORDINARY REMINDER ─────────────────────────────────
 * Because the dangerous code already exists and is proven: claim-token fencing,
 * the two-attempt ceiling under a row lock, the lease sweeper, the WhatsApp
 * delivery policy. A second proactive-delivery subsystem would duplicate all of
 * it and drift. So each ask is a `reminders` row carrying `follow_up_id` and
 * `ask_index`, and `UNIQUE (follow_up_id, ask_index)` is the LOGICAL ASK
 * IDENTITY — the authority that two racing materialisers cannot get past.
 *
 * ── WHAT IS DELIBERATELY NOT HERE ────────────────────────────────────────────
 *  - No `expires_at` / deadline column: V1 has no deadline concept, and a column
 *    nothing writes is a promise the schema does not keep.
 *  - No `asks_sent` counter, and no counter at all: the ask budget is DERIVED
 *    from DELIVERY TRUTH — `count(*)` over the ask rows whose reminder reached
 *    `sent`. `attempts` is deliberately not that truth: it is incremented in the
 *    transaction that commits BEFORE the network request, so it cannot tell a
 *    delivered question from a worker that died on the way to the provider. With
 *    nothing counted up, a template-refused ask cannot quietly spend a
 *    subscriber's budget, concurrency cannot double-count, and no number can
 *    drift from the rows it claims to describe.
 *  - No `messages` change: the resolving message is pointed at from here
 *    (`resolved_by_message_id`), so reply correlation needed no Message schema.
 *  - No finance change, and no follow-up-specific usage dimension: an ask's
 *    delivery already writes the ordinary reminder usage rows.
 *
 * One-time reminders and recurring occurrences are untouched: both new columns
 * on `reminders` are NULL for them, and multiple NULLs are permitted by the
 * unique index on PostgreSQL and SQLite alike.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * PROVENANCE. The inbound message that asked for this follow-up —
             * the same message the explicit-intent gate read as authority. Kept
             * so an operator can always answer "why does Sanad think it may ask
             * about this?" from a row rather than from a guess.
             */
            $table->foreignId('source_message_id')->nullable()->constrained('messages')->nullOnDelete();

            // An optional link to the task this loop is about. Completing that
            // task resolves the loop, which is the one resolution path that does
            // not need the subscriber to answer a question twice.
            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();

            // The subscriber's own wording of what is being followed up on. This
            // is SUBSCRIBER CONTENT, not metadata: the admin surface renders it
            // only under a content-level permission.
            $table->string('question', 200);

            // Where asks are delivered, and the wall clock they are read in.
            // Both from trusted context at creation — never model arguments.
            $table->string('channel');
            $table->string('timezone');

            $table->string('status', 24)->default(FollowUpStatus::Open->value);

            /*
             * The ask budget, SNAPSHOTTED at creation. A later configuration
             * change must not retroactively lengthen a ladder a subscriber is
             * already inside — raising the limit should apply to new loops, not
             * license two more messages about an old one.
             */
            $table->unsignedTinyInteger('max_asks');

            /*
             * When the next ask becomes due, in UTC.
             *
             * Unlike the recurrence cursor this IS authority for timing, because
             * the subscriber supplied it: V1 refuses to create a follow-up
             * without a definite time, and there is no hidden default anywhere in
             * the domain. It is NULL exactly when no further ask is scheduled.
             */
            $table->timestamp('next_ask_at')->nullable();

            // The inbound message whose words actually closed the loop. An
            // identifier, never a copy of the content.
            $table->foreignId('resolved_by_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('terminated_at')->nullable();

            // The operational hold: a CLOSED reason, because a block that cannot
            // be named cannot be cleared.
            $table->string('blocked_reason', 32)->nullable();
            $table->timestamp('blocked_at')->nullable();

            // Optimistic-concurrency fence: resolution and cancellation bump it,
            // so a materialiser that read this loop a moment ago cannot create an
            // ask after the loop closed.
            $table->unsignedInteger('version')->default(1);

            $table->timestamps();

            // The materialiser's read: live loops whose next ask is due.
            $table->index(['status', 'next_ask_at'], 'follow_ups_status_next_ask_idx');
            // The list tool, the per-subscriber cap, and the reply-correlation
            // lookup ("is there exactly one loop awaiting an answer?").
            $table->index(['user_id', 'status'], 'follow_ups_user_status_idx');
        });

        Schema::table('reminders', function (Blueprint $table) {
            // NULL for every one-time reminder and every recurring occurrence.
            $table->foreignId('follow_up_id')
                ->nullable()
                ->after('occurrence_local_at')
                ->constrained('follow_ups')
                ->nullOnDelete();

            // 1, 2, 3 … within one loop. Small on purpose: the ask budget is a
            // product bound in the low single digits, and a wide column would
            // suggest otherwise.
            $table->unsignedTinyInteger('ask_index')->nullable()->after('follow_up_id');

            /*
             * THE LOGICAL ASK IDENTITY. Two materialisers racing for ask n both
             * try to insert `(follow_up_id, n)`; one wins and the other reads the
             * existing row and treats it as success. No cursor, no timing and no
             * scheduler overlap guard is involved in that argument.
             */
            $table->unique(['follow_up_id', 'ask_index'], 'reminders_follow_up_ask_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            $states = "'".implode("', '", FollowUpStatus::values())."'";
            $terminal = "'".implode("', '", array_values(array_diff(
                FollowUpStatus::values(),
                FollowUpStatus::liveValues(),
            )))."'";
            $reasons = "'".implode("', '", FollowUpBlockReason::values())."'";

            // The vocabulary is a code table; the database keeps each row
            // coherent whatever writes it.
            DB::statement("alter table follow_ups add constraint follow_ups_status_check check (status in ({$states}))");
            DB::statement("alter table follow_ups add constraint follow_ups_block_reason_check check (blocked_reason is null or blocked_reason in ({$reasons}))");

            // `blocked` and a reason are one fact, and neither half is valid alone.
            DB::statement("alter table follow_ups add constraint follow_ups_blocked_coherent_check check ((status = 'blocked') = (blocked_reason is not null and blocked_at is not null))");

            // A terminal loop has a termination stamp, and a live one has none.
            DB::statement("alter table follow_ups add constraint follow_ups_terminated_coherent_check check ((status in ({$terminal})) = (terminated_at is not null))");

            // Resolution evidence belongs to resolved loops only.
            DB::statement("alter table follow_ups add constraint follow_ups_resolved_coherent_check check ((status in ('resolved_confirmed', 'resolved_by_task')) = (resolved_at is not null))");

            // A loop that is finished never has another ask scheduled.
            DB::statement('alter table follow_ups add constraint follow_ups_no_next_ask_when_done_check check (terminated_at is null or next_ask_at is null)');

            // The budget is a bound, so it must be one.
            DB::statement('alter table follow_ups add constraint follow_ups_max_asks_check check (max_asks >= 1)');

            // An ask belongs to a loop and a loop's ask has an index: both, or
            // neither — the shape that keeps ordinary reminders unconstrained.
            DB::statement('alter table reminders add constraint reminders_follow_up_ask_coherent_check check ((follow_up_id is null) = (ask_index is null))');
            DB::statement('alter table reminders add constraint reminders_ask_index_check check (ask_index is null or ask_index >= 1)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach ([
                'reminders' => ['reminders_follow_up_ask_coherent_check', 'reminders_ask_index_check'],
                'follow_ups' => [
                    'follow_ups_status_check',
                    'follow_ups_block_reason_check',
                    'follow_ups_blocked_coherent_check',
                    'follow_ups_terminated_coherent_check',
                    'follow_ups_resolved_coherent_check',
                    'follow_ups_no_next_ask_when_done_check',
                    'follow_ups_max_asks_check',
                ],
            ] as $table => $constraints) {
                foreach ($constraints as $constraint) {
                    DB::statement("alter table {$table} drop constraint if exists {$constraint}");
                }
            }
        }

        Schema::table('reminders', function (Blueprint $table) {
            $table->dropUnique('reminders_follow_up_ask_unique');
            $table->dropConstrainedForeignId('follow_up_id');
            $table->dropColumn('ask_index');
        });

        Schema::dropIfExists('follow_ups');
    }
};
