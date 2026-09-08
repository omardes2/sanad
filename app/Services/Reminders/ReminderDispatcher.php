<?php

declare(strict_types=1);

namespace App\Services\Reminders;

use App\Channels\ChannelRegistry;
use App\Data\ChannelDeliveryResult;
use App\Data\OutboundMessageData;
use App\Data\Reminders\ReminderClaim;
use App\Data\Reminders\ReminderDeliveryPlan;
use App\Data\Reminders\ReminderRecipient;
use App\Enums\MessageDeliveryStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageProcessingStatus;
use App\Enums\MessageType;
use App\Enums\ReminderFailureReason;
use App\Enums\ReminderStatus;
use App\Exceptions\WhatsAppConfigurationException;
use App\Exceptions\WhatsAppSendException;
use App\Models\Message;
use App\Models\Reminder;
use App\Support\SafeError;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Delivers due reminders. This is an EXTERNAL write, and the guarantee is a
 * bounded AT-LEAST-ONCE — never exactly-once.
 *
 * The provider send cannot join the database transaction, so the F3-V1
 * transactional guarantee (which holds only because a local write shares this
 * database) does not apply and is not implied anywhere here. The provider also
 * accepts no client-supplied idempotency key, so there is nothing to collapse a
 * duplicate on its side.
 *
 * CLAIM AND DISPATCH ARE DIFFERENT EVENTS
 * ---------------------------------------
 * A claim is bookkeeping; a dispatch is a real request. They are separated
 * because conflating them mis-counts the retry budget: a process that claims a
 * reminder and dies before the HTTP call has sent nothing, and must not be
 * charged an attempt for it.
 *
 *   claim      pending → processing, a NEW claim_token, claimed_at = now,
 *              dispatched_at cleared. attempts UNCHANGED.
 *
 *   authorise  a short locked transaction immediately before the request:
 *              re-check the status, that the stored token still equals the
 *              one this worker carries, that this claim has not already
 *              dispatched, that the budget is unspent and that the reminder is
 *              still timely; then attempts + 1 and dispatched_at = now, and
 *              commit. ONLY after that commit may a request leave.
 *
 * FENCING — WHO OWNS THE CLAIM
 * ----------------------------
 * `claim_token` is the ownership identity, and nothing else is. A worker is
 * handed the token at claim time and carries it (through the queue) to the
 * dispatch; every mutating path re-reads the row and requires the stored token
 * to equal the one it carries.
 *
 * That is what fences out the dangerous case: worker A claims, stalls, the
 * sweeper decides A is stale and returns the reminder to `pending`, worker B
 * claims it, and A finally wakes. A holds a token nobody recognises any more,
 * so it cannot increment `attempts`, cannot send, and cannot settle the row —
 * whatever the two claims' timestamps look like, however coarse the columns
 * are, and however the engine serialises them. `status === processing` says
 * only that SOMEONE owns it, and ordering two timestamps cannot say WHO; a
 * token that is new on every claim can.
 *
 * Within a claim, `dispatched_at` (cleared by every claim) is the plain
 * "already dispatched" marker, so one claim authorises exactly one physical
 * send: a second worker holding the same token reads it and stops.
 *
 * THE CRASH WINDOWS
 * -----------------
 *   W1  authorise never committed          → nothing left the platform.
 *                                            attempts is still 0, recovery is
 *                                            free of duplicate risk, and the
 *                                            retry budget is untouched.
 *   W2  request accepted, settlement lost  → indistinguishable from W3.
 *   W3  transport gave no usable answer    → indistinguishable from W2.
 *
 * W2 and W3 collapse into ONE state, `unknown`: a dispatch happened and Sanad
 * can prove neither delivery nor failure. It is never recorded as a failure and
 * never as confirmed zero cost. The sweeper allows exactly one more dispatch,
 * which is why the ceiling is 2 physical sends per reminder and never more.
 */
final class ReminderDispatcher
{
    /**
     * Physical send attempts per reminder occurrence. A structural ceiling, not
     * a tunable: a reminder that has already been dispatched twice is never
     * dispatched again, whatever configuration says.
     */
    public const MAX_ATTEMPTS = 2;

    public function __construct(
        private readonly ChannelRegistry $channels,
        private readonly ReminderRecipientResolver $recipients,
        private readonly ReminderDeliveryPolicy $policy,
        private readonly ReminderUsageRecorder $usage,
    ) {}

    /**
     * Claim up to $limit due reminders. Bookkeeping only — no attempt is
     * counted and nothing is sent.
     *
     * The claim is a conditional single-row update whose affected-row count is
     * the arbiter: 1 means this worker owns it, 0 means someone else claimed it
     * or the subscriber cancelled it in between. That is correct on PostgreSQL
     * and SQLite alike, needs no FOR UPDATE SKIP LOCKED, and does not depend on
     * the scheduler's overlap guard for correctness.
     *
     * Each winner is handed a FRESH token — the identity it must carry to be
     * allowed to dispatch later — and the previous claim's `dispatched_at` is
     * cleared, so within this claim that column is a plain fact rather than
     * something to compare.
     *
     * @return list<ReminderClaim> the claims this worker owns
     */
    public function claimDue(int $limit): array
    {
        if (! (bool) config('reminders.enabled', true)) {
            return [];
        }

        $now = CarbonImmutable::now();

        $candidates = Reminder::query()
            ->due()
            ->orderBy('remind_at')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->pluck('id');

        $claimed = [];

        foreach ($candidates as $id) {
            $token = (string) Str::uuid();

            $affected = DB::table('reminders')
                ->where('id', $id)
                ->where('status', ReminderStatus::Pending->value)
                ->update([
                    'status' => ReminderStatus::Processing->value,
                    'claim_token' => $token,
                    'claimed_at' => $now,
                    'dispatched_at' => null,
                    'updated_at' => $now,
                ]);

            if ($affected === 1) {
                $claimed[] = new ReminderClaim((int) $id, $token);
            }
        }

        return $claimed;
    }

    /**
     * Deliver one claimed reminder. Every exit is either a settled reminder or
     * a row the sweeper can safely recover.
     *
     * The FIRST thing checked is ownership, before any mutation at all: a
     * worker whose claim was swept and replaced must not settle the reminder
     * either — marking someone else's live claim `too_late` or `no_recipient`
     * would be just as wrong as sending under it.
     */
    public function deliver(ReminderClaim $claim): void
    {
        $reminder = Reminder::query()->find($claim->reminderId);

        if ($reminder === null
            || $reminder->status !== ReminderStatus::Processing
            || ! $reminder->isClaimedBy($claim->token)) {
            return;
        }

        if ($this->tooLate($reminder)) {
            $this->fail($reminder, ReminderFailureReason::TooLate);

            return;
        }

        $recipient = $this->recipients->resolve($reminder);

        if ($recipient === null) {
            $this->fail($reminder, ReminderFailureReason::NoRecipient);

            return;
        }

        $plan = $this->policy->decide($reminder, $recipient);

        if (! $plan->permitted) {
            // `template_required` and `delivery_disabled` are external-readiness
            // failures, not transient ones. Retrying asks the same question and
            // gets the same answer, so they are terminal immediately.
            $this->fail($reminder, $plan->refusal ?? ReminderFailureReason::Internal);

            return;
        }

        // The outbound row is created BEFORE the send, keyed uniquely on the
        // reminder, so a duplicate worker loses the insert instead of producing
        // a second message. It is savepoint-safe on PostgreSQL.
        $message = $this->outboundMessage($reminder, $recipient);

        $authorised = $this->authoriseDispatch($claim);

        if ($authorised === null) {
            // This claim is no longer ours, another worker already dispatched
            // under it, the budget is spent, or the reminder moved on. Nothing
            // is sent and nothing is written: whatever owns it now decides.
            return;
        }

        $this->send($authorised, $message, $recipient, $plan);
    }

    /**
     * Recover reminders stuck in `processing` past their lease.
     *
     * Never physically dispatched ⇒ straight back to pending with zero
     * duplicate risk. Dispatched with an unproven outcome and budget left ⇒ back
     * to pending for the ONE permitted retry. Otherwise terminal.
     *
     * @return array{recovered: int, failed: int}
     */
    public function sweep(): array
    {
        $lease = max(30, (int) config('reminders.lease_seconds', 300));
        $cutoff = CarbonImmutable::now()->subSeconds($lease);

        $stale = Reminder::query()
            ->where('status', ReminderStatus::Processing->value)
            ->where('claimed_at', '<=', $cutoff)
            ->orderBy('id')
            ->limit(max(1, (int) config('reminders.batch', 100)))
            ->get();

        $recovered = 0;
        $failed = 0;

        foreach ($stale as $reminder) {
            if ($this->tooLate($reminder)) {
                $failed += $this->settleStale($reminder, ReminderStatus::Failed, ReminderFailureReason::TooLate);

                continue;
            }

            if ($reminder->attempts >= self::MAX_ATTEMPTS) {
                $failed += $this->settleStale($reminder, ReminderStatus::Failed, ReminderFailureReason::AttemptsExhausted);

                continue;
            }

            $recovered += $this->settleStale($reminder, ReminderStatus::Pending, null);
        }

        return ['recovered' => $recovered, 'failed' => $failed];
    }

    /**
     * The short locked transaction that turns "claimed" into "allowed to send
     * exactly one request". Returns the reminder with its incremented attempt,
     * or null when this worker may not send.
     *
     * Ownership is re-read here under the row lock and not trusted from the
     * earlier check: between the two, a sweep and a re-claim can have happened,
     * and this is the last moment before a real request leaves.
     */
    private function authoriseDispatch(ReminderClaim $claim): ?Reminder
    {
        return DB::transaction(function () use ($claim): ?Reminder {
            /** @var Reminder|null $locked */
            $locked = Reminder::query()->whereKey($claim->reminderId)->lockForUpdate()->first();

            if ($locked === null
                || $locked->status !== ReminderStatus::Processing
                // Fencing: still OUR claim, not merely someone's.
                || ! $locked->isClaimedBy($claim->token)
                // One claim authorises at most one request.
                || $locked->dispatchedUnderCurrentClaim()
                || $locked->attempts >= self::MAX_ATTEMPTS
                || $this->tooLate($locked)) {
                return null;
            }

            $locked->forceFill([
                'attempts' => $locked->attempts + 1,
                'dispatched_at' => CarbonImmutable::now(),
            ])->save();

            return $locked;
        });
    }

    private function send(Reminder $reminder, Message $message, ReminderRecipient $recipient, ReminderDeliveryPlan $plan): void
    {
        $outbound = new OutboundMessageData(
            channel: $recipient->account->channel,
            externalUserId: $recipient->account->external_identifier,
            type: MessageType::Text,
            text: (string) $message->text_content,
            metadata: ['reminder_id' => $reminder->getKey()],
            template: $plan->template,
        );

        try {
            // retryTransient: false — one authorised attempt is exactly one
            // physical request. An in-adapter retry would turn a single counted
            // attempt into several real, unsolicited messages.
            $result = $this->channels->for($recipient->account->channel)->send($outbound, retryTransient: false);
        } catch (WhatsAppConfigurationException) {
            // Nothing was sent: the integration is not able to send at all.
            $this->fail($reminder, ReminderFailureReason::DeliveryDisabled);

            return;
        } catch (WhatsAppSendException $e) {
            $e->isPermanentRejection()
                ? $this->fail($reminder, ReminderFailureReason::Rejected)
                : $this->markUnknown($reminder, $e);

            return;
        } catch (Throwable $e) {
            // Any other failure at the transport boundary is equally unproven.
            $this->markUnknown($reminder, $e);

            return;
        }

        $this->settleAccepted($reminder, $message, $recipient, $result, $plan->template !== null);
    }

    /**
     * The provider accepted the message. Record the cost FIRST, then settle.
     *
     * Order matters: the provider has already served and billed this request,
     * so its cost must not depend on a later local write succeeding. If
     * settlement is then lost, the reminder stays recoverable and a second real
     * send would honestly produce its own second ledger row.
     */
    private function settleAccepted(
        Reminder $reminder,
        Message $message,
        ReminderRecipient $recipient,
        ChannelDeliveryResult $result,
        bool $viaTemplate,
    ): void {
        $this->usage->record($reminder, $recipient, $result, $viaTemplate);

        $now = CarbonImmutable::now();

        DB::transaction(function () use ($reminder, $message, $result, $now): void {
            $attributes = [
                'provider_message_id' => $result->providerMessageId,
                'delivery_status' => $result->status,
                'processing_status' => MessageProcessingStatus::Processed,
                'processed_at' => $now,
            ];

            // A channel that reports "sent" immediately stamps sent_at now;
            // WhatsApp reports "accepted" and later status webhooks advance it.
            if ($result->status === MessageDeliveryStatus::Sent) {
                $attributes['sent_at'] = $now;
            }

            $message->forceFill($attributes)->save();

            $reminder->forceFill([
                'status' => ReminderStatus::Sent->value,
                'sent_at' => $now,
                'last_error' => null,
                // The claim is over; a terminal reminder is owned by no one.
                'claim_token' => null,
            ])->save();
        });

        Log::info('sanad.reminder.delivered', [
            'reminder_id' => $reminder->getKey(),
            'attempt' => $reminder->attempts,
            'channel' => $recipient->account->channel->value,
            'template' => $viaTemplate,
        ]);
    }

    /**
     * A dispatch happened and its outcome is neither proven nor disproven.
     *
     * The row stays `processing`: this is NOT a failure, and it is NOT a
     * confirmed zero-cost event — the provider may well have accepted and
     * charged for a message we cannot see. No usage row can honestly be written
     * for it, and the absence of one must never be read as confirmed zero.
     * The sweeper decides whether the remaining budget allows one more send.
     */
    private function markUnknown(Reminder $reminder, Throwable $e): void
    {
        $reminder->forceFill(['last_error' => ReminderFailureReason::Unknown->value])->save();

        Log::warning('sanad.reminder.dispatch_unknown', [
            'reminder_id' => $reminder->getKey(),
            'attempt' => $reminder->attempts,
            'error' => SafeError::summarize($e),
        ]);
    }

    private function fail(Reminder $reminder, ReminderFailureReason $reason): void
    {
        $reminder->forceFill([
            'status' => ReminderStatus::Failed->value,
            'last_error' => $reason->value,
            // The claim is over; a terminal reminder is owned by no one.
            'claim_token' => null,
        ])->save();

        Log::warning('sanad.reminder.failed', [
            'reminder_id' => $reminder->getKey(),
            'attempts' => $reminder->attempts,
            'reason' => $reason->value,
        ]);
    }

    /**
     * Move a stale reminder, but only if it is still under exactly the claim
     * the sweeper observed — the token is the compare-and-set, so a worker that
     * legitimately re-claimed in the meantime is never robbed of its claim, and
     * two sweepers can never both recover the same one.
     */
    private function settleStale(Reminder $reminder, ReminderStatus $to, ?ReminderFailureReason $reason): int
    {
        $now = CarbonImmutable::now();

        $attributes = ['status' => $to->value, 'updated_at' => $now];

        if ($to === ReminderStatus::Pending) {
            // Released. The claim is over, so its identity goes with it; the
            // next claim issues a new token and clears `dispatched_at`. What
            // survives is `attempts`, which is what bounds the retry budget.
            $attributes['claim_token'] = null;
            $attributes['claimed_at'] = null;
        }

        if ($reason !== null) {
            $attributes['last_error'] = $reason->value;
        }

        $query = DB::table('reminders')
            ->where('id', $reminder->getKey())
            ->where('status', ReminderStatus::Processing->value);

        // A row with no token is not owned by anyone; matching it needs IS NULL
        // rather than `= NULL`, which never matches on either engine.
        $query = $reminder->claim_token === null
            ? $query->whereNull('claim_token')
            : $query->where('claim_token', $reminder->claim_token);

        return $query->update($attributes) === 1 ? 1 : 0;
    }

    /**
     * Create the outbound row exactly once for this reminder, PostgreSQL-safe:
     * a concurrent worker loses the unique insert on `reminder_id` and receives
     * the existing row rather than creating a second message.
     */
    private function outboundMessage(Reminder $reminder, ReminderRecipient $recipient): Message
    {
        return Message::query()->createOrFirst(
            ['reminder_id' => $reminder->getKey()],
            [
                'conversation_id' => $recipient->conversation->id,
                'user_id' => $reminder->user_id,
                'direction' => MessageDirection::Outbound,
                'type' => MessageType::Text,
                'text_content' => self::body($reminder),
                'metadata' => ['source' => 'reminder'],
                'processing_status' => MessageProcessingStatus::Queued,
            ],
        );
    }

    private static function body(Reminder $reminder): string
    {
        return 'تذكير: '.$reminder->title;
    }

    /**
     * The occasion has passed. Delivering a reminder long after its moment is
     * worse than not delivering it, so this is terminal rather than retried.
     */
    private function tooLate(Reminder $reminder): bool
    {
        $minutes = max(0, (int) config('reminders.max_lateness_minutes', 60));

        return CarbonImmutable::instance($reminder->remind_at)
            ->addMinutes($minutes)
            ->lessThan(CarbonImmutable::now());
    }
}
