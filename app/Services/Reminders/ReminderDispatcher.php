<?php

declare(strict_types=1);

namespace App\Services\Reminders;

use App\Channels\ChannelRegistry;
use App\Data\ChannelDeliveryResult;
use App\Data\OutboundMessageData;
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
 *   claim      pending → processing, claimed_at = now.
 *              attempts UNCHANGED, dispatched_at UNTOUCHED.
 *
 *   authorise  a short locked transaction immediately before the request:
 *              re-check the status, that this claim has not already dispatched,
 *              that the budget is unspent and that the reminder is still
 *              timely; then attempts + 1 and dispatched_at = now, and commit.
 *              ONLY after that commit may a request leave.
 *
 * `dispatched_at >= claimed_at` therefore means "a request was authorised under
 * the current claim", which is what makes one claim authorise exactly one
 * physical send: a second worker holding the same claim reads it and stops.
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
     * @return list<int> the ids this worker owns
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
            $affected = DB::table('reminders')
                ->where('id', $id)
                ->where('status', ReminderStatus::Pending->value)
                ->update([
                    'status' => ReminderStatus::Processing->value,
                    'claimed_at' => $now,
                    'updated_at' => $now,
                ]);

            if ($affected === 1) {
                $claimed[] = (int) $id;
            }
        }

        return $claimed;
    }

    /**
     * Deliver one claimed reminder. Every exit is either a settled reminder or
     * a row the sweeper can safely recover.
     */
    public function deliver(int $reminderId): void
    {
        $reminder = Reminder::query()->find($reminderId);

        if ($reminder === null || $reminder->status !== ReminderStatus::Processing) {
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

        $authorised = $this->authoriseDispatch($reminder);

        if ($authorised === null) {
            // Another worker already dispatched under this claim, the budget is
            // spent, or the reminder moved on. Nothing is sent and nothing is
            // written: whatever owns it now decides its outcome.
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
     */
    private function authoriseDispatch(Reminder $reminder): ?Reminder
    {
        return DB::transaction(function () use ($reminder): ?Reminder {
            /** @var Reminder|null $locked */
            $locked = Reminder::query()->whereKey($reminder->getKey())->lockForUpdate()->first();

            if ($locked === null
                || $locked->status !== ReminderStatus::Processing
                || $locked->claimed_at === null
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
        ])->save();

        Log::warning('sanad.reminder.failed', [
            'reminder_id' => $reminder->getKey(),
            'attempts' => $reminder->attempts,
            'reason' => $reason->value,
        ]);
    }

    /**
     * Move a stale reminder, but only if it is still exactly as observed —
     * `claimed_at` is the token, so a worker that legitimately re-claimed in
     * the meantime is never robbed of its claim.
     */
    private function settleStale(Reminder $reminder, ReminderStatus $to, ?ReminderFailureReason $reason): int
    {
        $now = CarbonImmutable::now();

        $attributes = ['status' => $to->value, 'updated_at' => $now];

        if ($to === ReminderStatus::Pending) {
            // Released for another claim. dispatched_at is kept: it is the
            // evidence that a request already went out under the old claim.
            $attributes['claimed_at'] = null;
        }

        if ($reason !== null) {
            $attributes['last_error'] = $reason->value;
        }

        $affected = DB::table('reminders')
            ->where('id', $reminder->getKey())
            ->where('status', ReminderStatus::Processing->value)
            ->where('claimed_at', $reminder->claimed_at)
            ->update($attributes);

        return $affected === 1 ? 1 : 0;
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
