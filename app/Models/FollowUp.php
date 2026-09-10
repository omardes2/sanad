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
 * THE BUDGET IS DERIVED FROM DELIVERY TRUTH, AND DELIVERY TRUTH IS `sent`.
 *
 * `asksSent()` counts ask rows whose reminder reached `ReminderStatus::Sent` —
 * the state the dispatcher writes, in one transaction with `sent_at`, and only
 * after the provider ACCEPTED the message. Nothing else counts as having asked the
 * subscriber anything.
 *
 * `attempts` is deliberately NOT used, and that is the correction this model
 * exists to make. The dispatcher increments `attempts` inside the locked
 * transaction that commits BEFORE the network request, so there is a real window
 * in which a row reads `attempts = 1` and no request was ever made: the worker
 * died between that commit and the send. From outside, that state is
 * indistinguishable from a request that left and whose answer was lost — which is
 * exactly why `attempts` can authorise a PHYSICAL RETRY (it is a spend ceiling)
 * and can never establish that a QUESTION was asked.
 *
 * There is no `asks_sent` column either, and no counter anywhere: the number is a
 * `count(*)` over the ask rows, so there is nothing to increment twice, nothing to
 * forget to increment, and nothing that can drift from the rows it describes.
 * Concurrency cannot double-count what is never counted up.
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
     * How many asks were PROVEN SENT — the logical ask budget, and the only
     * meaning of "we asked" in this domain.
     *
     * `ReminderStatus::Sent` is the trusted delivery state: the dispatcher writes
     * it together with `sent_at`, in one transaction, and only after the provider
     * accepted the message. A claim does not count. An authorised attempt does not
     * count. A refusal before dispatch does not count. A worker that died between
     * incrementing `attempts` and reaching the network does not count — nothing was
     * asked, so nothing is spent.
     */
    public function asksSent(): int
    {
        return $this->asks()->where('status', ReminderStatus::Sent->value)->count();
    }

    public function budgetRemaining(): int
    {
        return max(0, $this->max_asks - $this->asksSent());
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
     * When the most recent PROVEN SENT ask reached the subscriber, or null if no
     * ask has been proven sent.
     *
     * `sent_at` from a `sent` row, and nothing else. `dispatched_at` is
     * deliberately ignored: it records that a request was AUTHORISED, which is
     * precisely the fact that survives a crash before the network. Measuring the
     * next-ask interval from it would start the clock on a question nobody
     * received.
     */
    public function lastSentAt(): ?CarbonImmutable
    {
        $sentAt = $this->asks()
            ->where('status', ReminderStatus::Sent->value)
            ->reorder()
            ->orderByDesc('ask_index')
            ->value('sent_at');

        return $sentAt === null ? null : CarbonImmutable::parse((string) $sentAt);
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
