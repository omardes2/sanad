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
 * `reminders.claim_token`   — the FENCING TOKEN of the current claim.
 * `reminders.claimed_at`    — when that claim was taken.
 * `reminders.dispatched_at` — the dispatch authorised under that claim, if any.
 *
 * `claim_token` is the ownership identity, and it is deliberately NOT a
 * timestamp. A worker carries the token it was handed at claim time and may act
 * only while the stored token still equals it. A worker whose claim was swept
 * and replaced holds a token nobody recognises any more, so it is structurally
 * unable to send or to touch the row — no matter how close the two claims were
 * in time, how coarse the timestamp columns are, or how the two engines
 * serialise them. Ordering timestamps can never be an ownership test.
 *
 * `dispatched_at` is cleared by every claim, so within a claim it reads as a
 * plain fact with no comparison at all:
 *
 *   NULL     ⇒ this claim has authorised nothing; nothing has left the
 *              platform under it. Recovery is free of duplicate risk.
 *   NOT NULL ⇒ this claim authorised a request, which may or may not have
 *              reached the provider. Sanad can prove neither, so it records
 *              neither. A second worker on the same claim sees it and stops.
 *
 * The count of real dispatches lives in `attempts`, which no claim ever
 * resets — that, not a timestamp, is what bounds the retry budget.
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
            // Ownership identity, not a lock: opaque, server-generated, and new
            // on every claim, so a stale worker's token can never match.
            $table->string('claim_token', 36)->nullable()->after('sent_at');
            $table->timestamp('claimed_at')->nullable()->after('claim_token');
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
            $table->dropColumn(['claim_token', 'claimed_at', 'dispatched_at']);
        });
    }
};
