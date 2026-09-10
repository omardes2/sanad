<?php

declare(strict_types=1);

namespace App\Services\FollowUps;

use App\Enums\ChannelType;
use App\Enums\FollowUpBlockReason;
use App\Enums\FollowUpStatus;
use App\Enums\ReminderFailureReason;
use App\Enums\ReminderStatus;
use App\Models\FollowUp;
use App\Models\Reminder;
use App\Services\Reminders\ReminderDeliveryPolicy;
use App\Services\Reminders\ReminderRecipientResolver;
use App\Support\SafeError;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Advances follow-up loops: creates the next ask when one is due, and moves the
 * state when reminder truth says something happened.
 *
 * It does not deliver, claim, settle or resolve. Every ask it writes is an
 * ORDINARY reminder, so the dispatcher and the sweeper pick it up knowing nothing
 * about follow-ups.
 *
 * ── ONE OUTSTANDING ASK AT A TIME ────────────────────────────────────────────
 * The ladder is never pre-created. Ask n+1 becomes eligible only when ask n was
 * PROVEN SENT (`ReminderStatus::Sent`), no answer closed the loop, the configured
 * interval has passed measured from `sent_at`, budget remains, and the loop is not
 * blocked. That is what makes the ladder stoppable: an answer means the next ask
 * was never created, rather than having to be hunted down and cancelled.
 *
 * ── DELIVERY TRUTH IS `sent`, AND `attempts` IS NOT DELIVERY TRUTH ───────────
 * The dispatcher increments `attempts` inside the locked transaction that commits
 * BEFORE the network request. So a row reading `attempts = 1` can mean either "a
 * request left and its answer was lost" or "the worker died before the send" —
 * the two are indistinguishable from outside, and in the second case the
 * subscriber was asked NOTHING. Progressing a ladder on `attempts` would
 * therefore ask a second question on the strength of a first that may never have
 * existed.
 *
 * So this file uses `attempts` for exactly nothing. What it reads is:
 *   - `sent`            → one logical ask, proven, counted, and the interval
 *                         starts from its `sent_at`;
 *   - pending/processing → NOTHING IS PROVEN. No progression, whatever `attempts`
 *                         says; the reminder subsystem's own bounded retry and
 *                         sweep decide what becomes of it;
 *   - terminally failed  → the ask was never proven sent, so the ladder must not
 *                         advance as though the subscriber received the question.
 *                         The loop is HELD (`blocked`), which spends no budget and
 *                         creates no replacement ask, and recovery is explicit.
 *
 * ── BLOCKED IS A HOLD, NOT A FAILURE ─────────────────────────────────────────
 * When the approved follow-up template is missing and the ask would fall outside
 * the service window, no ask is created at all: the loop goes to `blocked`. A
 * blocked loop produces nothing and spends nothing, so a configuration gap can
 * neither drain a subscriber's ladder nor fill the queue with refused deliveries.
 * Recovery is the explicit, idempotent `sanad:follow-ups:unblock`.
 *
 * ── RACES ────────────────────────────────────────────────────────────────────
 * Every write happens inside a transaction that re-reads the loop
 * `lockForUpdate()` and re-checks its status and `version`. Resolution and
 * cancellation bump that version, so a run that decided to ask a moment ago
 * cannot insert after the loop closed. The ask itself is keyed
 * `(follow_up_id, ask_index)` UNIQUE, which is what makes two concurrent
 * materialisers produce one row rather than two messages.
 */
final class FollowUpAskMaterialiser
{
    /**
     * The failure reasons that name a CONFIGURATION OR ACCOUNT gap, mapped to the
     * hold they produce. Every other terminal failure — `unknown`, an exhausted
     * attempt budget, `too_late`, a provider rejection — also holds the loop, under
     * `DeliveryUnavailable`: what they have in common is that `sent` was never
     * established, and the ladder may not advance on a question that was never
     * proven to arrive.
     */
    private const BLOCKING_REASONS = [
        ReminderFailureReason::TemplateRequired->value => FollowUpBlockReason::TemplateUnavailable,
        ReminderFailureReason::DeliveryDisabled->value => FollowUpBlockReason::DeliveryUnavailable,
        ReminderFailureReason::NoRecipient->value => FollowUpBlockReason::DeliveryUnavailable,
        ReminderFailureReason::ChannelUnsupported->value => FollowUpBlockReason::DeliveryUnavailable,
    ];

    public function __construct(
        private readonly ReminderRecipientResolver $recipients,
        private readonly ReminderDeliveryPolicy $policy,
    ) {}

    /**
     * Walk the loops that may need attention and advance each one.
     *
     * @return array{follow_ups: int, asks: int}
     */
    public function run(): array
    {
        if (! $this->enabled()) {
            return ['follow_ups' => 0, 'asks' => 0];
        }

        $now = CarbonImmutable::now('UTC');

        $candidates = FollowUp::query()
            ->live()
            // `blocked` loops are inert by construction: they are excluded here so
            // no run can turn a configuration gap into repeated failed asks.
            ->whereIn('status', [FollowUpStatus::Open->value, FollowUpStatus::AwaitingAnswer->value])
            ->where(fn ($q) => $q
                ->where('status', FollowUpStatus::AwaitingAnswer->value)
                ->orWhere(fn ($open) => $open
                    ->where('status', FollowUpStatus::Open->value)
                    ->whereNotNull('next_ask_at')
                    ->where('next_ask_at', '<=', $now)))
            ->orderByRaw('next_ask_at is null desc')
            ->orderBy('next_ask_at')
            ->orderBy('id')
            ->limit(max(1, (int) config('follow_ups.materialise_batch', 100)))
            ->get();

        $asks = 0;
        $touched = 0;

        foreach ($candidates as $followUp) {
            try {
                $asks += $this->advance($followUp) === 'created' ? 1 : 0;
                $touched++;
            } catch (Throwable $e) {
                // One malformed loop must not stop the others.
                Log::error('sanad.follow_ups.advance_failed', [
                    'follow_up_id' => $followUp->getKey(),
                    'error' => SafeError::summarize($e),
                ]);
            }
        }

        if ($asks > 0) {
            Log::info('sanad.follow_ups.asked', ['follow_ups' => $touched, 'asks' => $asks]);
        }

        return ['follow_ups' => $touched, 'asks' => $asks];
    }

    /**
     * Advance ONE loop by at most one step, under a lock.
     *
     * @return string what happened: inert | in_flight | asked | waiting | blocked | abandoned | created
     */
    public function advance(FollowUp $followUp): string
    {
        if (! $this->enabled()) {
            return 'inert';
        }

        return (string) DB::transaction(function () use ($followUp): string {
            /** @var FollowUp|null $fresh */
            $fresh = FollowUp::query()->whereKey($followUp->getKey())->lockForUpdate()->first();

            if ($fresh === null || $fresh->isTerminal() || $fresh->status === FollowUpStatus::Blocked) {
                // The fence: resolved, cancelled, abandoned or held. Whatever this
                // run decided a moment ago is from a world that no longer exists.
                return 'inert';
            }

            $now = CarbonImmutable::now('UTC');
            $latest = $fresh->latestAsk();

            if ($latest !== null) {
                if (in_array($latest->status, [ReminderStatus::Pending, ReminderStatus::Processing], true)) {
                    /*
                     * An ask is in flight and NOTHING IS PROVEN — whatever
                     * `attempts` reads. It may be claimed, it may have been
                     * authorised, the worker may have died a microsecond before the
                     * network: none of that is a question the subscriber received.
                     * So the loop does not advance, does not count a logical ask,
                     * and creates nothing (one outstanding ask at a time). The
                     * reminder subsystem's own bounded retry and sweep decide what
                     * this row becomes; this run simply waits for that truth.
                     */
                    return 'in_flight';
                }

                if ($latest->status === ReminderStatus::Sent) {
                    /*
                     * PROVEN SENT: the subscriber was asked. This is the only
                     * transition that counts a logical ask, and the count is derived
                     * from the row rather than incremented anywhere.
                     *
                     * `next_ask_at === null` is the test for "nothing else is
                     * scheduled", which distinguishes a fresh send from a loop the
                     * subscriber DELIBERATELY re-opened with «لسا» — that one owns
                     * its own schedule and must not be dragged back to awaiting.
                     */
                    if ($fresh->status !== FollowUpStatus::AwaitingAnswer && $fresh->next_ask_at === null) {
                        $this->markAwaiting($fresh);

                        return 'asked';
                    }

                    if ($fresh->status !== FollowUpStatus::AwaitingAnswer) {
                        // Re-opened by an answer: the due check below honours the
                        // time that answer produced.
                        return $this->createIfDue($fresh, $now);
                    }

                    $sentAt = $fresh->lastSentAt() ?? $now;
                    $interval = max(1, (int) config('follow_ups.min_ask_interval_hours', 24));

                    if ($now->lessThan($sentAt->addHours($interval))) {
                        return 'waiting';
                    }

                    if ($fresh->budgetRemaining() < 1) {
                        // The budget is spent. Terminal and SILENT: Sanad stops
                        // asking, and never escalates.
                        $this->abandon($fresh, $now);

                        return 'abandoned';
                    }

                    /*
                     * Another ask is due — and the loop STAYS `awaiting_answer`
                     * while it is created. That is not bookkeeping:
                     * `awaiting_answer` is what the reply correlator requires, so
                     * flipping to `open` for the few milliseconds it takes to insert
                     * the next ask would open a window in which a subscriber's «آه»
                     * is refused for arriving at the wrong instant. The question is
                     * still outstanding; asking it again does not change that.
                     */
                    $fresh->forceFill(['next_ask_at' => $now])->save();
                } elseif ($latest->status === ReminderStatus::Failed) {
                    /*
                     * TERMINALLY FAILED WITHOUT `sent`. Whether the provider refused
                     * it before dispatch, or a request left and its outcome stayed
                     * unknown until the attempt budget ran out, the conclusion for
                     * the LADDER is the same: Sanad cannot establish that the
                     * subscriber was asked, so it must not act as though they were.
                     *
                     * The loop is HELD rather than retried. No budget is spent, no
                     * replacement ask is created to fail the same way, and recovery
                     * is an explicit operator action — which is also what stops an
                     * unknown outcome from quietly becoming either "sent" or
                     * "not sent".
                     */
                    $this->block(
                        $fresh,
                        self::BLOCKING_REASONS[(string) $latest->last_error] ?? FollowUpBlockReason::DeliveryUnavailable,
                        $now,
                    );

                    return 'blocked';
                } else {
                    // Cancelled: only the cancellation path cancels an ask, and that
                    // terminates the loop, so this run has nothing to do.
                    return 'waiting';
                }
            }

            return $this->createIfDue($fresh, $now);
        });
    }

    /**
     * The shared due check: create the next ask when a time is set and has arrived.
     *
     * BOTH live non-blocked states reach here. `open` means nothing is outstanding
     * (nothing asked yet, or the subscriber said «لسا» and the interval has since
     * passed), and `awaiting_answer` means the previous ask was proven sent, went
     * unanswered, and another is owed. The state says whether a question is
     * outstanding; `next_ask_at` says whether one is due.
     */
    private function createIfDue(FollowUp $followUp, CarbonImmutable $now): string
    {
        if (! in_array($followUp->status, [FollowUpStatus::Open, FollowUpStatus::AwaitingAnswer], true)
            || $followUp->next_ask_at === null
            || CarbonImmutable::parse((string) $followUp->next_ask_at)->greaterThan($now)) {
            return 'waiting';
        }

        if ($followUp->budgetRemaining() < 1) {
            $this->abandon($followUp, $now);

            return 'abandoned';
        }

        $block = $this->preflight($followUp);

        if ($block !== null) {
            $this->block($followUp, $block, $now);

            return 'blocked';
        }

        return $this->createAsk($followUp, $now) ? 'created' : 'waiting';
    }

    /**
     * Create the next ask as an ordinary reminder.
     *
     * `createOrFirst` keyed on the logical ask identity, and NOT `create()` inside
     * a try/catch: this runs inside the guarded transaction, and on PostgreSQL a
     * unique violation ABORTS the surrounding transaction, so catching it would
     * leave every later statement failing. `createOrFirst` uses a SAVEPOINT, so a
     * concurrent materialiser that inserted the same ask first simply makes this a
     * no-op — which is success: the ask exists, which is all anyone asked for.
     */
    private function createAsk(FollowUp $followUp, CarbonImmutable $now): bool
    {
        $index = $followUp->nextAskIndex();

        $ask = Reminder::query()->createOrFirst(
            [
                'follow_up_id' => $followUp->getKey(),
                'ask_index' => $index,
            ],
            [
                'user_id' => $followUp->user_id,
                // Provenance travels to the ask, which is also how the recipient
                // resolver finds the thread the subscriber asked in.
                'source_message_id' => $followUp->source_message_id,
                'title' => (string) $followUp->question,
                'remind_at' => $now,
                'timezone' => $followUp->timezone,
                'channel' => $followUp->channel->value,
                'status' => ReminderStatus::Pending->value,
            ],
        );

        // The ask is now the outstanding thing; nothing else is scheduled until
        // reminder truth says this one left the platform.
        $followUp->forceFill(['next_ask_at' => null])->save();

        return $ask->wasRecentlyCreated;
    }

    /**
     * Could an ask be delivered at all right now — and if not, is that a durable
     * configuration fact worth holding the loop for?
     *
     * The window question is asked through `ReminderDeliveryPolicy` so there is
     * exactly one implementation of it: inside the service window a free-form ask
     * is permitted and no template is needed; outside it, an approved FOLLOW-UP
     * template is the only permitted mechanism, and without one the loop is held
     * rather than being allowed to produce a refused delivery.
     */
    private function preflight(FollowUp $followUp): ?FollowUpBlockReason
    {
        // A transient reminder: the resolver reads only the owner, the channel and
        // the provenance, so this asks "where would this ask go?" without creating
        // a row that would then have to be cleaned up.
        $probe = new Reminder([
            'user_id' => $followUp->user_id,
            'source_message_id' => $followUp->source_message_id,
            'channel' => $followUp->channel->value,
        ]);

        $recipient = $this->recipients->resolve($probe);

        if ($recipient === null) {
            return FollowUpBlockReason::DeliveryUnavailable;
        }

        if ($followUp->channel !== ChannelType::WhatsApp) {
            return null;
        }

        if ($this->policy->insideFreeFormWindow($recipient)) {
            // The subscriber is inside the service window, so this ask is
            // permitted as a free-form message and needs no template.
            return null;
        }

        return $this->policy->hasFollowUpTemplate() ? null : FollowUpBlockReason::TemplateUnavailable;
    }

    private function markAwaiting(FollowUp $followUp): void
    {
        $followUp->forceFill([
            'status' => FollowUpStatus::AwaitingAnswer->value,
            'next_ask_at' => null,
        ])->save();
    }

    private function block(FollowUp $followUp, FollowUpBlockReason $reason, CarbonImmutable $now): void
    {
        $followUp->forceFill([
            'status' => FollowUpStatus::Blocked->value,
            'blocked_reason' => $reason->value,
            'blocked_at' => $now,
            // No further ask is scheduled while the hold stands. Recovery is
            // explicit, so this cannot quietly resume.
            'next_ask_at' => null,
        ])->save();

        Log::warning('sanad.follow_ups.blocked', [
            'follow_up_id' => $followUp->getKey(),
            'reason' => $reason->value,
        ]);
    }

    private function abandon(FollowUp $followUp, CarbonImmutable $now): void
    {
        $followUp->forceFill([
            'status' => FollowUpStatus::Abandoned->value,
            'terminated_at' => $now,
            'next_ask_at' => null,
            'blocked_reason' => null,
            'blocked_at' => null,
            'version' => $followUp->version + 1,
        ])->save();
    }

    private function enabled(): bool
    {
        return (bool) config('follow_ups.enabled', true)
            && (bool) config('reminders.enabled', true);
    }
}
