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
     * Whether a physical send has already been authorised under the CURRENT
     * claim. One claim authorises at most one request: a second worker holding
     * the same claim sees this and stops, so a claim can never fan out into two
     * unsolicited messages.
     */
    public function dispatchedUnderCurrentClaim(): bool
    {
        return $this->claimed_at !== null
            && $this->dispatched_at !== null
            && $this->dispatched_at->greaterThanOrEqualTo($this->claimed_at);
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
