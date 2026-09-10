<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChannelType;
use App\Enums\ReminderPattern;
use App\Enums\ReminderScheduleStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A recurrence DEFINITION — never a delivery.
 *
 * It answers one question: which nominal local wall-clock moments does this
 * series fall on? Turning one of those into an instant is the planner's job, and
 * delivering it is the dispatcher's job on an ordinary `Reminder` row. The
 * schedule has no claim, no attempts and no `sent_at`, deliberately: the moment
 * a definition starts carrying delivery state is the moment one row starts
 * serving many sends.
 *
 * TERMINATION IS ONE-WAY. There is no pause and no in-place edit in V1 — a
 * changed recurrence is this one terminated and another created, so the history
 * always shows which definition produced which occurrence, and no already-
 * materialised occurrence is ever silently re-timed.
 */
class ReminderSchedule extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'source_message_id',
        'title',
        'channel',
        'timezone',
        'pattern',
        'local_time',
        'weekdays',
        'day_of_month',
        'starts_on',
        'ends_on',
        'status',
        'terminated_at',
        'materialised_through',
        'version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => ChannelType::class,
            'pattern' => ReminderPattern::class,
            'status' => ReminderScheduleStatus::class,
            'weekdays' => 'array',
            'day_of_month' => 'integer',
            // Local CALENDAR dates, not instants: they are read in the
            // subscriber's zone and never compared against now().
            'starts_on' => 'date',
            'ends_on' => 'date',
            'materialised_through' => 'date',
            'terminated_at' => 'datetime',
            'version' => 'integer',
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

    /**
     * The occurrences materialised from this definition — ordinary reminders,
     * each with its own identity and its own delivery state.
     *
     * @return HasMany<Reminder, $this>
     */
    public function occurrences(): HasMany
    {
        return $this->hasMany(Reminder::class, 'reminder_schedule_id');
    }

    public function isActive(): bool
    {
        return $this->status === ReminderScheduleStatus::Active;
    }

    /**
     * Has the series' own local window closed?
     *
     * Compared as LOCAL CALENDAR DATES in the schedule's zone, because that is
     * what the subscriber said. Comparing an end date against a UTC instant
     * would end a series up to a day early or late depending on the offset.
     */
    public function windowClosed(string $localDate): bool
    {
        return $this->ends_on !== null && $localDate > $this->ends_on->format('Y-m-d');
    }

    /**
     * @param  Builder<ReminderSchedule>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', ReminderScheduleStatus::Active->value);
    }

    /**
     * A short, non-sensitive description of the recurrence, for the list tool
     * and the admin surface. It carries no subscriber content beyond the title
     * the subscriber themselves chose, and never an identifier of anyone else.
     */
    public function recurrenceSummary(): string
    {
        return match ($this->pattern) {
            ReminderPattern::Daily => "كل يوم {$this->local_time}",
            ReminderPattern::Weekly => 'كل '.$this->weekdayNames()." {$this->local_time}",
            ReminderPattern::Monthly => "كل شهر يوم {$this->day_of_month} {$this->local_time}",
        };
    }

    private function weekdayNames(): string
    {
        $names = [1 => 'الاثنين', 2 => 'الثلاثاء', 3 => 'الأربعاء', 4 => 'الخميس', 5 => 'الجمعة', 6 => 'السبت', 7 => 'الأحد'];

        $days = array_map(
            static fn (int $day): string => $names[$day] ?? (string) $day,
            array_map('intval', (array) ($this->weekdays ?? [])),
        );

        return implode(' و', $days);
    }
}
