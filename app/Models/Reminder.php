<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChannelType;
use App\Enums\ReminderStatus;
use Database\Factories\ReminderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    /** @return BelongsTo<Message, $this> */
    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'source_message_id');
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
