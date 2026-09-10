<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChannelType;
use App\Enums\FollowUpBlockReason;
use App\Enums\FollowUpStatus;
use App\Enums\ReminderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ONE open loop Sanad is following up on: the definition and the state, never a
 * delivery.
 *
 * Every physical ask is an ordinary `Reminder` pointing back here, so everything
 * about claiming, attempting, sending and settling belongs to those rows and not
 * to this one. What lives here is the only thing a per-delivery row cannot hold:
 * whether the loop is still open, and how much of the ask budget is left.
 *
 * THE BUDGET IS DERIVED, NOT COUNTED. `asksUsed()` reads the ask rows and counts
 * those with `attempts > 0` — i.e. those for which a request genuinely left the
 * platform. There is deliberately no `asks_sent` column, because a counter can
 * drift from the rows it claims to describe and, worse, a refusal that never
 * reached a provider (no approved template, a channel that cannot send) would
 * have to be remembered NOT to increment it. Reading reminder truth makes that
 * impossible to get wrong: an ask that was refused before dispatch simply has
 * `attempts = 0` and was never an ask.
 */
class FollowUp extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'source_message_id',
        'task_id',
        'question',
        'channel',
        'timezone',
        'status',
        'max_asks',
        'next_ask_at',
        'resolved_by_message_id',
        'resolved_at',
        'terminated_at',
        'blocked_reason',
        'blocked_at',
        'version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => ChannelType::class,
            'status' => FollowUpStatus::class,
            'blocked_reason' => FollowUpBlockReason::class,
            'max_asks' => 'integer',
            'version' => 'integer',
            'next_ask_at' => 'datetime',
            'resolved_at' => 'datetime',
            'terminated_at' => 'datetime',
            'blocked_at' => 'datetime',
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
     * The inbound message that asked for this follow-up — the evidence the
     * explicit-intent gate read. Provenance, never re-interpreted later.
     *
     * @return BelongsTo<Message, $this>
     */
    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'source_message_id');
    }

    /**
     * The inbound message whose words closed the loop.
     *
     * @return BelongsTo<Message, $this>
     */
    public function resolvedByMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'resolved_by_message_id');
    }

    /**
     * Every ask, newest last. Each one is an ordinary reminder with its own
     * claim, its own attempt budget and its own outbound message.
     *
     * @return HasMany<Reminder, $this>
     */
    public function asks(): HasMany
    {
        return $this->hasMany(Reminder::class, 'follow_up_id')->orderBy('ask_index');
    }

    public function isLive(): bool
    {
        return $this->status->isLive();
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * How many asks have GENUINELY left the platform.
     *
     * `attempts > 0` is the whole definition, and it is reminder truth rather
     * than a follow-up opinion: the dispatcher increments `attempts` inside the
     * locked transaction immediately before the physical request, so a row with
     * `attempts > 0` is one the provider may well have delivered — including the
     * `unknown` outcome, which must never be re-read as "not sent" merely
     * because retrying would be cheaper than admitting uncertainty.
     */
    public function asksUsed(): int
    {
        return $this->asks()->where('attempts', '>', 0)->count();
    }

    public function budgetRemaining(): int
    {
        return max(0, $this->max_asks - $this->asksUsed());
    }

    /**
     * The ask that is still in flight: created, not yet settled. While one
     * exists, no further ask may be created — ONE OUTSTANDING ASK AT A TIME is
     * what keeps a ladder from becoming a burst.
     */
    public function outstandingAsk(): ?Reminder
    {
        /** @var Reminder|null $ask */
        $ask = $this->asks()
            ->whereIn('status', [ReminderStatus::Pending->value, ReminderStatus::Processing->value])
            ->orderByDesc('ask_index')
            ->first();

        return $ask;
    }

    public function latestAsk(): ?Reminder
    {
        /** @var Reminder|null $ask */
        $ask = $this->asks()->reorder()->orderByDesc('ask_index')->first();

        return $ask;
    }

    /**
     * When the most recent ask actually left the platform, or null if none has.
     *
     * Both stamps are consulted because a `sent` ask has `sent_at` while an ask
     * whose outcome is `unknown` has only `dispatched_at` — and the second kind
     * counts: the subscriber may have received it.
     */
    public function lastAskedAt(): ?CarbonImmutable
    {
        $row = $this->asks()
            ->where('attempts', '>', 0)
            ->reorder()
            ->orderByDesc('ask_index')
            ->first(['sent_at', 'dispatched_at']);

        if ($row === null) {
            return null;
        }

        $stamps = array_values(array_filter([$row->sent_at, $row->dispatched_at]));

        if ($stamps === []) {
            return null;
        }

        $latest = null;

        foreach ($stamps as $stamp) {
            $moment = CarbonImmutable::parse((string) $stamp);

            if ($latest === null || $moment->greaterThan($latest)) {
                $latest = $moment;
            }
        }

        return $latest;
    }

    /** The next index an ask may take. Identity, not a count of deliveries. */
    public function nextAskIndex(): int
    {
        return 1 + (int) $this->asks()->max('ask_index');
    }

    /**
     * Live follow-ups — the ones the materialiser and the reply correlator may
     * still touch.
     *
     * @param  Builder<FollowUp>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->whereIn('status', FollowUpStatus::liveValues());
    }

    /**
     * Exactly the loops that could be answered right now.
     *
     * @param  Builder<FollowUp>  $query
     */
    public function scopeAwaitingAnswer(Builder $query): void
    {
        $query->where('status', FollowUpStatus::AwaitingAnswer->value);
    }
}
