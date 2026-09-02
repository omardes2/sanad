<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'title',
        'description',
        'status',
        'priority',
        'due_at',
        'completed_at',
        'source_message_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Message, $this> */
    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'source_message_id');
    }

    /** @return HasMany<Reminder, $this> */
    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class);
    }

    /**
     * Tasks that are not yet done (pending or in progress).
     *
     * @param  Builder<Task>  $query
     */
    public function scopeIncomplete(Builder $query): void
    {
        $query->whereIn('status', [TaskStatus::Pending, TaskStatus::InProgress]);
    }
}
