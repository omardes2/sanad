<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChannelType;
use App\Enums\ReminderFailureReason;
use App\Enums\ReminderStatus;
use Database\Factories\ReminderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Reminder extends Model
{
    /** @use HasFactory<ReminderFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'task_id',
        'source_message_id',
        'reminder_schedule_id',
        'occurrence_key',
        'occurrence_local_at',
        'title',
        'remind_at',
        'timezone',
        'channel',
        'status',
        'sent_at',
        'claim_token',
        'claimed_at',
        'dispatched_at',
        'attempts',
        'last_error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'remind_at' => 'datetime',
            'occurrence_local_at' => 'datetime',
            'sent_at' => 'datetime',
            'claimed_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'channel' => ChannelType::class,
            'status' => ReminderStatus::class,
            'attempts' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * The recurrence definition this row is ONE OCCURRENCE of, or null for a
     * one-time reminder.
     *
     * @return BelongsTo<ReminderSchedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ReminderSchedule::class, 'reminder_schedule_id');
    }

    /**
     * Is this row one occurrence of a series?
     *
     * Nothing in the delivery path asks this question, and that is the point: an
     * occurrence is an ordinary reminder with its own claim, its own attempt
     * budget and its own outbound message, so the dispatcher, the sweeper and
     * the delivery policy are identical for both kinds. This exists for the
     * admin surface and for the cancellation scopes.
     */
    public function isOccurrence(): bool
    {
        return $this->reminder_schedule_id !== null;
    }

    /** @return BelongsTo<Message, $this> */
    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'source_message_id');
    }

    /**
     * The message this reminder was actually delivered as, if any. `reminder_id`
     * is UNIQUE on `messages`, so there is at most one.
     *
     * @return HasOne<Message, $this>
     */
    public function deliveredMessage(): HasOne
    {
        return $this->hasOne(Message::class, 'reminder_id');
    }

    /**
     * `last_error` as the bounded code it actually holds.
     *
     * Deliberately an ACCESSOR and not an enum cast. `ReminderDispatcher` is the
     * only writer and only ever writes `ReminderFailureReason` values, but the
     * column is free-form `text` from Sprint 0 — so an enum cast would throw on
     * any row that ever held prose, and an admin page must not be the thing that
     * crashes on legacy data. Unknown values come back as null here and are
     * rendered verbatim from `last_error` instead.
     */
    public function failureReason(): ?ReminderFailureReason
    {
        $value = $this->getAttribute('last_error');

        return is_string($value) && $value !== '' ? ReminderFailureReason::tryFrom($value) : null;
    }

    /**
     * Claimed, past its lease, and never settled — the shape a crashed worker
     * leaves behind. The sweeper recovers these; the dashboard only reports them.
     */
    public function isStaleClaim(): bool
    {
        if ($this->status !== ReminderStatus::Processing || $this->claimed_at === null) {
            return false;
        }

        $lease = max(60, (int) config('reminders.lease_seconds', 300));

        return $this->claimed_at->copy()->addSeconds($lease)->isPast();
    }

    /**
     * Whether the worker carrying $token still holds this reminder's claim.
     *
     * This is the ONLY ownership test. `status === processing` says someone is
     * working on it, not that it is still YOU; and no comparison of `claimed_at`
     * against `dispatched_at` can answer the question either, whatever the
     * column precision or the engine's serialisation — two claims taken close
     * together are indistinguishable by time, and a stale worker would sail
     * through. A token that is new on every claim is distinguishable always.
     */
    public function isClaimedBy(string $token): bool
    {
        return $this->claim_token !== null && hash_equals($this->claim_token, $token);
    }

    /**
     * Whether a physical send has already been authorised under the CURRENT
     * claim. Every claim clears `dispatched_at`, so this is a plain fact about
     * this claim with no timestamp comparison in it: a second worker holding
     * the same token reads it and stops, and a claim can never fan out into two
     * unsolicited messages.
     */
    public function dispatchedUnderCurrentClaim(): bool
    {
        return $this->dispatched_at !== null;
    }

    /**
     * Reminders that are due to be sent: still pending and their time has come.
     * remind_at is stored in UTC, so we compare against UTC "now".
     *
     * @param  Builder<Reminder>  $query
     */
    public function scopeDue(Builder $query): void
    {
        $query->where('status', ReminderStatus::Pending)
            ->where('remind_at', '<=', now());
    }
}
