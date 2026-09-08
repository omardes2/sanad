<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reminder delivery — the three facts the scheduler needs, and nothing else.
 *
 * `messages.reminder_id` (UNIQUE)
 *     At most ONE outbound message per reminder occurrence — the exact
 *     analogue of `in_reply_to_message_id`, which gives a reminder no key
 *     because a reminder has no inbound message. The row is created before the
 *     send, so a duplicate worker loses the unique insert instead of producing
 *     a second message. Recurrence, when it comes, produces one reminder row
 *     per occurrence, so this key stays correct.
 *
 * `reminders.claimed_at`    — when the current claim was taken.
 * `reminders.dispatched_at` — when a physical send was last authorised.
 *
 * Those two timestamps together are the whole crash-window contract:
 *
 *   dispatched_at IS NULL or dispatched_at < claimed_at
 *       ⇒ nothing has left the platform under THIS claim. Recovery is free of
 *         duplicate risk, and one claim can authorise at most one physical
 *         send (the second worker sees dispatched_at >= claimed_at and stops).
 *         Whole-second precision is sufficient and the >= is deliberate: a
 *         dispatch inside the same second as its claim compares equal, which
 *         correctly stops a second worker, and a later claim always lands at
 *         least one lease-length after the dispatch it supersedes.
 *
 *   dispatched_at >= claimed_at
 *       ⇒ a request was authorised and may or may not have reached the
 *         provider. Sanad cannot prove accepted or rejected, so it is never
 *         recorded as either.
 *
 * `attempts` and `last_error` already exist (Sprint 0) and finally get a
 * writer; `attempts` counts PHYSICAL dispatch attempts only, never claims.
 *
 * Index (status, claimed_at) serves the stale-processing sweeper exactly;
 * the existing (status, remind_at) already serves the due query.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reminders', function (Blueprint $table) {
            $table->timestamp('claimed_at')->nullable()->after('sent_at');
            $table->timestamp('dispatched_at')->nullable()->after('claimed_at');

            $table->index(['status', 'claimed_at'], 'reminders_status_claimed_idx');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('reminder_id')
                ->nullable()
                ->constrained('reminders')
                ->nullOnDelete();

            // At most one outbound message per reminder. Non-reminder rows keep
            // this NULL, and multiple NULLs are allowed on both PostgreSQL and
            // SQLite, so ordinary messages are unconstrained.
            $table->unique('reminder_id');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropUnique(['reminder_id']);
            $table->dropConstrainedForeignId('reminder_id');
        });

        Schema::table('reminders', function (Blueprint $table) {
            $table->dropIndex('reminders_status_claimed_idx');
            $table->dropColumn(['claimed_at', 'dispatched_at']);
        });
    }
};
