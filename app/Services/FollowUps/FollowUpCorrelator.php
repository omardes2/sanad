<?php

declare(strict_types=1);

namespace App\Services\FollowUps;

use App\Data\FollowUps\FollowUpCorrelation;
use App\Enums\MessageDirection;
use App\Models\Conversation;
use App\Models\FollowUp;
use App\Models\Message;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Establishes whether an inbound message can be read as a reply to a follow-up
 * ask — and refuses to guess when it cannot.
 *
 * WHY THIS IS HARD HERE. WhatsApp gives no reference from a reply to the message
 * it answers, and the platform has no inbound→outbound correlation column. The
 * tempting shortcut — "the subscriber has an open follow-up and just said
 * something affirmative, so that must be the answer" — is exactly the bug: a
 * «تمام» in a conversation about something else would close a loop the subscriber
 * never addressed, and they would never hear about it again.
 *
 * So correlation must be EARNED from stored facts, all of which must hold:
 *
 *   1. the loop belongs to THIS subscriber;
 *   2. it is `awaiting_answer` — an ask genuinely left the platform;
 *   3. the message arrived AFTER that ask;
 *   4. it arrived inside the configured answer window, because «تمام» eight days
 *      later is a reply to today's conversation, not to last week's question;
 *   5. it arrived on the SAME channel the ask was sent to;
 *   6. it is the ONLY loop that fits — two candidates mean the subscriber's one
 *      word cannot say which, so the model must ask.
 *
 * Nothing here mutates anything. It answers a question; the service decides.
 */
final class FollowUpCorrelator
{
    /**
     * The loop this message answers, or a refusal naming the missing fact.
     */
    public function correlate(User $subscriber, Message $message): FollowUpCorrelation
    {
        if ($message->direction !== MessageDirection::Inbound
            || (int) $message->user_id !== (int) $subscriber->getKey()) {
            // Sanad's own words are never an answer, and another subscriber's
            // message is never evidence about this one's loops.
            return FollowUpCorrelation::refused('none');
        }

        /** @var list<FollowUp> $candidates */
        $candidates = FollowUp::query()
            ->where('user_id', $subscriber->getKey())
            ->awaitingAnswer()
            ->orderBy('id')
            ->get()
            ->all();

        if ($candidates === []) {
            return FollowUpCorrelation::refused('none');
        }

        $arrivedAt = CarbonImmutable::parse((string) $message->created_at);
        $channel = $this->channelOf($message);
        $window = max(1, (int) config('follow_ups.answer_window_hours', 72));

        $fits = [];
        $reason = 'none';

        foreach ($candidates as $candidate) {
            $askedAt = $candidate->lastAskedAt();

            if ($askedAt === null) {
                // `awaiting_answer` without a dispatched ask should not exist; if
                // it does, it is not evidence of anything.
                continue;
            }

            if ($arrivedAt->lessThanOrEqualTo($askedAt)) {
                // A message that predates the question cannot answer it — which
                // also fences out a reply racing the ask's own dispatch.
                $reason = 'before_ask';

                continue;
            }

            if ($arrivedAt->greaterThan($askedAt->addHours($window))) {
                $reason = 'too_late';

                continue;
            }

            if ($channel !== null && $candidate->channel->value !== $channel) {
                $reason = 'channel_mismatch';

                continue;
            }

            $fits[] = $candidate;
        }

        if ($fits === []) {
            return FollowUpCorrelation::refused($reason);
        }

        if (count($fits) > 1) {
            // TWO OPEN QUESTIONS, ONE «آه». The domain must not pick; the model
            // asks which one the subscriber means.
            return FollowUpCorrelation::refused('ambiguous');
        }

        return FollowUpCorrelation::correlated($fits[0]);
    }

    /**
     * The channel this message arrived on, read through the conversation's own
     * channel account — the same trusted path the write executor uses.
     */
    private function channelOf(Message $message): ?string
    {
        if ($message->conversation_id === null) {
            return null;
        }

        $channel = Conversation::query()
            ->whereKey($message->conversation_id)
            ->join('channel_accounts', 'channel_accounts.id', '=', 'conversations.channel_account_id')
            ->value('channel_accounts.channel');

        return $channel === null ? null : (string) (is_object($channel) ? $channel->value : $channel);
    }
}
