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
 * The ladder is never pre-created. Ask n+1 becomes eligible only when ask n has
 * GENUINELY LEFT THE PLATFORM (`attempts > 0`), no answer closed the loop, the
 * configured interval has passed, budget remains, and the loop is not blocked.
 * That is what makes the ladder stoppable: an answer means the next ask was never
 * created, rather than having to be hunted down and cancelled.
 *
 * ── THE BUDGET IS REMINDER TRUTH ─────────────────────────────────────────────
 * `attempts > 0` is the only definition of "we asked". So:
 *   - an ask refused BEFORE dispatch (no approved template, channel cannot send)
 *     has `attempts = 0` and was never an ask — it spends no budget;
 *   - an ask whose provider outcome is `unknown` HAS `attempts > 0` and counts,
 *     because the subscriber may well have received it. Re-reading it as "not
 *     sent" because retrying would be cheaper is exactly the dishonesty the
 *     reminder design refuses.
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
     * Failure reasons that mean NOTHING LEFT THE PLATFORM and never will, until
     * configuration or the subscriber's account changes. They are the blocking
     * conditions; every other reason is an ordinary delivery failure.
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
                $settled = ! in_array($latest->status, [ReminderStatus::Pending, ReminderStatus::Processing], true);

                if (! $settled) {
                    // An ask is in flight. ONE OUTSTANDING ASK AT A TIME, so
                    // nothing new is created — but if it has already been
                    // dispatched, the loop is now waiting for an answer.
                    if ($latest->attempts > 0 && $fresh->status !== FollowUpStatus::AwaitingAnswer) {
                        $this->markAwaiting($fresh);

                        return 'asked';
                    }

                    return 'in_flight';
                }

                if ($latest->attempts === 0 && $latest->status === ReminderStatus::Failed) {
                    /*
                     * The ask never reached a provider: the policy refused it
                     * before burning an attempt. That is a configuration or
                     * account fact, not a delivery failure — so the loop is HELD,
                     * no budget is spent, and crucially no further ask is created
                     * to fail the same way.
                     */
                    $reason = self::BLOCKING_REASONS[(string) $latest->last_error] ?? null;

                    if ($reason !== null) {
                        $this->block($fresh, $reason, $now);

                        return 'blocked';
                    }
                }

                if ($latest->attempts > 0) {
                    /*
                     * It left the platform. THREE CASES, and the difference between
                     * them is who decided what happens next:
                     */
                    if ($fresh->status === FollowUpStatus::AwaitingAnswer) {
                        // Nobody answered. The interval decides, measured from the
                        // ask itself rather than from this run.
                        $askedAt = $fresh->lastAskedAt() ?? $now;
                        $interval = max(1, (int) config('follow_ups.min_ask_interval_hours', 24));

                        if ($now->lessThan($askedAt->addHours($interval))) {
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
                         * while it is created.
                         *
                         * This is not bookkeeping: `awaiting_answer` is what the
                         * reply correlator requires, so flipping to `open` for the
                         * few milliseconds it takes to insert the next ask would
                         * open a window in which a subscriber's «آه» is refused for
                         * arriving at the wrong instant. The question is still
                         * outstanding; asking it a second time does not change that.
                         */
                        $fresh->forceFill(['next_ask_at' => $now])->save();
                    } elseif ($fresh->next_ask_at === null) {
                        /*
                         * The ask has just landed and nothing else is scheduled, so
                         * the loop is now waiting for an answer. That transition is
                         * a STEP IN ITSELF and the run ends here — deciding to ask
                         * again in the same pass as noticing the last one landed
                         * would collapse two states into one.
                         */
                        $this->markAwaiting($fresh);

                        return 'asked';
                    }
                    /*
                     * Otherwise the loop was DELIBERATELY re-opened with a time —
                     * the subscriber answered «لسا», and `next_ask_at` is the
                     * schedule that answer produced. It is not the materialiser's
                     * place to drag it back to `awaiting_answer`; the due check
                     * below honours the time instead.
                     */
                }
            }

            /*
             * An ask is due when a time is set and has arrived. BOTH live
             * non-blocked states qualify: `open` (nothing asked yet, or the
             * subscriber said «لسا» and the interval has since passed) and
             * `awaiting_answer` (the previous ask went unanswered and another is
             * owed). The state says whether a question is outstanding; `next_ask_at`
             * says whether one is due.
             */
            if (! in_array($fresh->status, [FollowUpStatus::Open, FollowUpStatus::AwaitingAnswer], true)
                || $fresh->next_ask_at === null
                || CarbonImmutable::parse((string) $fresh->next_ask_at)->greaterThan($now)) {
                return 'waiting';
            }

            if ($fresh->budgetRemaining() < 1) {
                $this->abandon($fresh, $now);

                return 'abandoned';
            }

            $block = $this->preflight($fresh);

            if ($block !== null) {
                $this->block($fresh, $block, $now);

                return 'blocked';
            }

            return $this->createAsk($fresh, $now) ? 'created' : 'waiting';
        });
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
