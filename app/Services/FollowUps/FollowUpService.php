<?php

declare(strict_types=1);

namespace App\Services\FollowUps;

use App\Enums\ChannelType;
use App\Enums\FollowUpAnswer;
use App\Enums\FollowUpStatus;
use App\Enums\PlanFeature;
use App\Enums\ReminderStatus;
use App\Exceptions\Tools\ToolDomainException;
use App\Models\FollowUp;
use App\Models\Message;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Services\Billing\SubscriptionService;
use App\Support\FollowUps\FollowUpAnswerReader;
use App\Support\FollowUps\FollowUpTimeEvidence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY writer of `follow_ups`, and the only place a loop is opened or closed.
 *
 * OWNERSHIP AND CONTEXT ARE NEVER ARGUMENTS, as for reminders: the subscriber
 * comes from the invocation's trusted context, the timezone from their profile,
 * the channel from the conversation the message arrived on. No tool schema
 * declares any of them, so a model cannot choose whose loop it is, which wall
 * clock it is read in, or where the asks will be delivered.
 *
 * TWO AUTHORITIES ARE REQUIRED TO OPEN A LOOP, and they are separate on purpose:
 *
 *   1. EXPLICIT INTENT — enforced before this service is reached
 *      (`ToolIntentRequirements` → `ExplicitFollowUpIntent`). It answers "may
 *      Sanad follow up at all?"
 *   2. A TIME THE SUBSCRIBER ACTUALLY GAVE — enforced here. Intent is not
 *      authority to invent a schedule, so without a definite moment in the
 *      subscriber's own words this refuses and Sanad ASKS WHEN. There is no
 *      default anywhere in this file: no tomorrow, no 24 hours, no "after the
 *      expected outcome".
 *
 * CLOSING A LOOP IS ALSO TWO-PART: the model may PROPOSE an outcome, and the
 * server verifies that the subscriber's own correlated message supports it.
 * Silence never resolves anything, and an unreadable reply is not a "no".
 */
final class FollowUpService
{
    /**
     * The HARD ceiling on what one listing may return. The tool's output schema
     * promises it at boot, so configuration may only narrow it from here.
     */
    public const LIST_MAX = 25;

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly FollowUpCorrelator $correlator,
    ) {}

    /**
     * Open one loop.
     *
     * @param  array{question: string, first_ask_at: string, task_id?: int|null}  $input
     * @return array{follow_up_id: int, first_ask_local: string, max_asks: int}
     *
     * @throws ToolDomainException
     */
    public function create(User $subscriber, array $input, ChannelType $channel, Message $message): array
    {
        if (! (bool) config('follow_ups.enabled', true)) {
            throw ToolDomainException::rule('المتابعة حتى الإنجاز غير متاحة حاليًا.');
        }

        // ENTITLEMENT on its own feature, never on `reminders`: a plan may offer
        // reminders without offering Sanad the right to come back unprompted.
        if (! $this->subscriptions->hasFeature($subscriber, PlanFeature::FollowUp)) {
            throw ToolDomainException::rule('المتابعة حتى الإنجاز غير مشمولة في باقة المشترك.');
        }

        $timezone = $this->timezone($subscriber);
        $question = $this->question($input['question'] ?? '');

        /*
         * THE TIMING RULE. Explicit follow-up intent authorises following up; it
         * does NOT authorise choosing when. If the subscriber's own words carry no
         * definite moment, nothing is created and the refusal says what to ask —
         * so Sanad asks «إيمتى تحب أتابع معك؟» instead of inventing tomorrow.
         */
        if (! FollowUpTimeEvidence::present($message)) {
            throw ToolDomainException::rule('ما في وقت محدَّد للمتابعة: اسأل المشترك إيمتى يحب تتابع معه، ولا تفترض وقتًا.');
        }

        $firstAskAt = $this->firstAskAt($input['first_ask_at'] ?? null);
        $task = $this->task($subscriber, $input['task_id'] ?? null);

        /*
         * The per-subscriber cap is enforced INSIDE a transaction that first locks
         * the SUBSCRIBER'S OWN ROW — the same reasoning as the recurring-schedule
         * cap: `SELECT … FOR UPDATE` cannot lock a row that does not exist yet, so
         * two concurrent creations one below the cap would both count room and
         * both insert. Locking the one row both transactions must touch
         * serialises them on something real.
         *
         * At the cap the new loop is REFUSED. Nothing existing is abandoned to
         * make space: a loop the subscriber asked Sanad to watch is not the
         * platform's to drop.
         */
        return DB::transaction(function () use ($subscriber, $channel, $message, $question, $timezone, $firstAskAt, $task): array {
            $cap = max(1, (int) config('follow_ups.max_open_per_subscriber', 5));

            User::query()->whereKey($subscriber->getKey())->lockForUpdate()->first();

            $live = FollowUp::query()
                ->where('user_id', $subscriber->getKey())
                ->live()
                ->count();

            if ($live >= $cap) {
                throw ToolDomainException::rule("وصلت الحدّ الأقصى للمتابعات المفتوحة ({$cap}). أنهِ واحدة قبل إضافة جديدة.");
            }

            $followUp = FollowUp::query()->create([
                'user_id' => $subscriber->getKey(),          // trusted context
                'source_message_id' => $message->getKey(),   // the evidence, kept
                'task_id' => $task?->getKey(),
                'question' => $question,
                'channel' => $channel->value,                // conversation context
                'timezone' => $timezone,                     // subscriber profile
                'status' => FollowUpStatus::Open->value,
                'max_asks' => $this->maxAsks(),              // snapshotted
                'next_ask_at' => $firstAskAt,
            ]);

            return [
                'follow_up_id' => (int) $followUp->getKey(),
                // The subscriber's own wall clock — the only form that means
                // anything to them.
                'first_ask_local' => $firstAskAt->setTimezone($timezone)->format('Y-m-d H:i'),
                'max_asks' => (int) $followUp->max_asks,
            ];
        });
    }

    /**
     * The subscriber's own follow-ups, bounded, live by default.
     *
     * Read-only and subscriber-isolated by construction: the owner comes from the
     * invocation context and there is no argument that could widen it. This tool
     * exists so «وقف متابعة البنك» can name a real loop instead of an id the model
     * remembered or invented.
     *
     * @param  array{limit?: int|null, include_closed?: bool|null}  $input
     * @return array{follow_ups: list<array<string, mixed>>, truncated: bool}
     */
    public function list(User $subscriber, array $input = []): array
    {
        $max = min(self::LIST_MAX, max(1, (int) config('follow_ups.list_limit', 10)));
        $limit = isset($input['limit']) && (int) $input['limit'] > 0
            ? min((int) $input['limit'], $max)
            : $max;

        $query = FollowUp::query()
            ->where('user_id', $subscriber->getKey())
            ->orderByDesc('id');

        if (($input['include_closed'] ?? false) !== true) {
            $query->live();
        }

        // One row more than the bound, so `truncated` is a fact rather than a
        // guess about whether anything was left out.
        $rows = $query->limit($limit + 1)->get();
        $truncated = $rows->count() > $limit;

        $followUps = $rows->take($limit)->map(fn (FollowUp $followUp): array => [
            'follow_up_id' => (int) $followUp->getKey(),
            // The subscriber's own words — the only way they will recognise which
            // loop this is.
            'question' => (string) $followUp->question,
            'status' => $followUp->status->value,
            'asks_used' => $followUp->asksUsed(),
            'max_asks' => (int) $followUp->max_asks,
            'next_ask_local' => $followUp->next_ask_at === null
                ? null
                : CarbonImmutable::parse((string) $followUp->next_ask_at)
                    ->setTimezone($followUp->timezone)->format('Y-m-d H:i'),
        ])->values()->all();

        return ['follow_ups' => $followUps, 'truncated' => $truncated];
    }

    /**
     * Close, or deliberately keep open, a loop on the strength of the
     * subscriber's reply.
     *
     * THREE CHECKS, ALL SERVER-SIDE. The correlator must establish that this
     * message can answer this loop at all; the reader must agree that the words
     * support the outcome being proposed; and the transition is then applied under
     * a row lock with a version bump, so a materialiser that read the loop a
     * moment ago cannot create an ask afterwards.
     *
     * @return array{follow_up_id: int, status: string, resolved: bool}
     *
     * @throws ToolDomainException
     */
    public function resolve(User $subscriber, int $followUpId, FollowUpAnswer $proposed, Message $message): array
    {
        if (! in_array($proposed->value, FollowUpAnswer::proposable(), true)) {
            throw ToolDomainException::rule('نتيجة المتابعة غير مدعومة.');
        }

        /** @var FollowUp|null $named */
        $named = FollowUp::query()
            ->whereKey($followUpId)
            ->where('user_id', $subscriber->getKey())   // ownership from context
            ->first();

        if ($named === null) {
            throw ToolDomainException::notFound('المتابعة');
        }

        /*
         * THE IDEMPOTENT REPEAT IS ANSWERED BEFORE CORRELATION IS EVEN ASKED FOR.
         *
         * A closed loop has no ask outstanding, so there is no evidence to
         * correlate against — and demanding some would turn "this is already
         * settled" into a refusal the model would have to interpret. Reporting the
         * state honestly needs no proof: the call simply was not the one that
         * closed it.
         */
        if ($named->isTerminal()) {
            return $this->outcome($named, resolved: false);
        }

        $correlation = $this->correlator->correlate($subscriber, $message);

        if (! $correlation->ok()) {
            throw ToolDomainException::rule(match ($correlation->reason) {
                'ambiguous' => 'أكثر من متابعة بانتظار جواب: اسأل المشترك أيّ واحدة يقصد قبل إنهائها.',
                'too_late' => 'الرسالة وصلت بعد نافذة الجواب، فلا يمكن ربطها بسؤال المتابعة.',
                'channel_mismatch' => 'الرسالة وصلت على قناة غير قناة سؤال المتابعة.',
                default => 'لا توجد متابعة بانتظار جواب يمكن ربط هذه الرسالة بها.',
            });
        }

        $followUp = $correlation->followUp;

        if ((int) $followUp->getKey() !== $followUpId) {
            // The model named a different loop than the one the evidence supports.
            throw ToolDomainException::rule('هذه الرسالة لا تُجيب على المتابعة المحدَّدة.');
        }

        // The model may PROPOSE; the subscriber's own words decide.
        if (! FollowUpAnswerReader::supports($message, $proposed)) {
            throw ToolDomainException::rule('كلام المشترك لا يدعم هذه النتيجة: اطلب توضيحًا ولا تفترض.');
        }

        return DB::transaction(function () use ($followUp, $proposed, $message): array {
            /** @var FollowUp|null $fresh */
            $fresh = FollowUp::query()->whereKey($followUp->getKey())->lockForUpdate()->first();

            if ($fresh === null) {
                throw ToolDomainException::notFound('المتابعة');
            }

            if ($fresh->isTerminal()) {
                // Idempotent and honest: the loop is closed, and this call is not
                // the one that closed it.
                return $this->outcome($fresh, resolved: false);
            }

            $now = CarbonImmutable::now('UTC');

            if ($proposed === FollowUpAnswer::Confirmed) {
                $this->cancelPendingAsks($fresh, $now);

                $fresh->forceFill([
                    'status' => FollowUpStatus::ResolvedConfirmed->value,
                    'resolved_by_message_id' => $message->getKey(),
                    'resolved_at' => $now,
                    'terminated_at' => $now,
                    'next_ask_at' => null,
                    'version' => $fresh->version + 1,
                ])->save();

                return $this->outcome($fresh, resolved: true);
            }

            /*
             * «لسا» — still open. The loop returns to `open` and AT MOST ONE more
             * ask becomes eligible, never immediately: `next_ask_at` is the last
             * ask plus the configured interval, so answering "not yet" can never
             * be what triggers the next message.
             */
            $askedAt = $fresh->lastAskedAt() ?? $now;
            $interval = max(1, (int) config('follow_ups.min_ask_interval_hours', 24));

            $fresh->forceFill([
                'status' => FollowUpStatus::Open->value,
                'next_ask_at' => $fresh->budgetRemaining() > 0 ? $askedAt->addHours($interval) : null,
                'version' => $fresh->version + 1,
            ])->save();

            return $this->outcome($fresh, resolved: false);
        });
    }

    /**
     * Stop asking. Subscriber-owned, idempotent, and one transaction.
     *
     * Termination and the cancellation of the pending ask happen together for the
     * same reason they do for a recurring series: separated, a crash between them
     * would leave a closed loop with a live ask still queued to fire — the
     * subscriber said stop and Sanad would ask anyway.
     *
     * @return array{follow_up_id: int, cancelled_asks: int, terminated: bool}
     *
     * @throws ToolDomainException
     */
    public function cancel(User $subscriber, int $followUpId): array
    {
        return DB::transaction(function () use ($subscriber, $followUpId): array {
            /** @var FollowUp|null $followUp */
            $followUp = FollowUp::query()
                ->whereKey($followUpId)
                ->where('user_id', $subscriber->getKey())   // ownership from context
                ->lockForUpdate()
                ->first();

            if ($followUp === null) {
                throw ToolDomainException::notFound('المتابعة');
            }

            $now = CarbonImmutable::now('UTC');
            $alreadyClosed = $followUp->isTerminal();

            if (! $alreadyClosed) {
                $followUp->forceFill([
                    'status' => FollowUpStatus::Cancelled->value,
                    'terminated_at' => $now,
                    'next_ask_at' => null,
                    'blocked_reason' => null,
                    'blocked_at' => null,
                    'version' => $followUp->version + 1,
                ])->save();
            }

            return [
                'follow_up_id' => (int) $followUp->getKey(),
                'cancelled_asks' => $this->cancelPendingAsks($followUp, $now),
                // Honest about an idempotent repeat.
                'terminated' => ! $alreadyClosed,
            ];
        });
    }

    /**
     * The task this loop was attached to was completed, so the loop is closed —
     * without asking the subscriber a question they have already answered by
     * doing the thing.
     *
     * Idempotent, and silent when nothing is linked: a completed task with no
     * follow-up is the overwhelmingly common case and must cost nothing.
     *
     * @return int how many loops this completion closed
     */
    public function resolveByTask(User $subscriber, int $taskId): int
    {
        $ids = FollowUp::query()
            ->where('user_id', $subscriber->getKey())
            ->where('task_id', $taskId)
            ->live()
            ->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        $closed = 0;

        foreach ($ids as $id) {
            $closed += DB::transaction(function () use ($id): int {
                /** @var FollowUp|null $fresh */
                $fresh = FollowUp::query()->whereKey($id)->lockForUpdate()->first();

                if ($fresh === null || $fresh->isTerminal()) {
                    return 0;
                }

                $now = CarbonImmutable::now('UTC');
                $this->cancelPendingAsks($fresh, $now);

                $fresh->forceFill([
                    'status' => FollowUpStatus::ResolvedByTask->value,
                    'resolved_at' => $now,
                    'terminated_at' => $now,
                    'next_ask_at' => null,
                    'blocked_reason' => null,
                    'blocked_at' => null,
                    'version' => $fresh->version + 1,
                ])->save();

                return 1;
            });
        }

        return $closed;
    }

    /**
     * Cancel the asks that have not left the platform yet.
     *
     * Only `pending` rows: an ask being delivered right now is not the loop's to
     * retract — the claim owns it — and a sent or failed one is a record, not a
     * plan. This is the same boundary recurring cancellation draws.
     */
    private function cancelPendingAsks(FollowUp $followUp, CarbonImmutable $now): int
    {
        return Reminder::query()
            ->where('follow_up_id', $followUp->getKey())
            ->where('status', ReminderStatus::Pending->value)
            ->update([
                'status' => ReminderStatus::Cancelled->value,
                'updated_at' => $now,
            ]);
    }

    /** @return array{follow_up_id: int, status: string, resolved: bool} */
    private function outcome(FollowUp $followUp, bool $resolved): array
    {
        return [
            'follow_up_id' => (int) $followUp->getKey(),
            'status' => $followUp->status->value,
            'resolved' => $resolved,
        ];
    }

    /** The subscriber's profile zone, validated — never a tool argument. */
    private function timezone(User $subscriber): string
    {
        $timezone = trim((string) ($subscriber->timezone ?? ''));

        if ($timezone === '' || ! in_array($timezone, timezone_identifiers_list(), true)) {
            throw ToolDomainException::rule('المنطقة الزمنية للمشترك غير مضبوطة، فلا يمكن جدولة متابعة.');
        }

        return $timezone;
    }

    private function question(mixed $raw): string
    {
        $question = trim((string) $raw);

        if ($question === '') {
            throw ToolDomainException::rule('المتابعة تحتاج وصفًا لما يُتابَع عليه.');
        }

        return mb_substr($question, 0, 200);
    }

    /** The first ask's instant: required, absolute, and in the future. */
    private function firstAskAt(mixed $raw): CarbonImmutable
    {
        $value = trim((string) ($raw ?? ''));

        if ($value === '') {
            throw ToolDomainException::rule('وقت المتابعة الأول مطلوب: اسأل المشترك إيمتى يحب تتابع معه.');
        }

        $at = CarbonImmutable::parse($value, 'UTC');

        if ($at->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
            throw ToolDomainException::rule('وقت المتابعة يجب أن يكون في المستقبل.');
        }

        return $at;
    }

    /**
     * The linked task, if one was named — and only if it is the SUBSCRIBER'S.
     *
     * An id from a model is not proof of ownership, so this is checked rather than
     * trusted: otherwise a wrong number would attach a loop to someone else's
     * task and complete on their actions.
     */
    private function task(User $subscriber, mixed $raw): ?Task
    {
        if ($raw === null || $raw === '' || (int) $raw <= 0) {
            return null;
        }

        /** @var Task|null $task */
        $task = Task::query()
            ->whereKey((int) $raw)
            ->where('user_id', $subscriber->getKey())
            ->first();

        if ($task === null) {
            throw ToolDomainException::notFound('المهمة');
        }

        return $task;
    }

    private function maxAsks(): int
    {
        return max(1, (int) config('follow_ups.max_asks_per_follow_up', 3));
    }
}
