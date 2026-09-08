<?php

declare(strict_types=1);

namespace App\Services\Reminders;

use App\Data\Reminders\ReminderRecipient;
use App\Enums\ChannelAccountStatus;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Reminder;

/**
 * Answers WHERE a reminder goes — and refuses rather than guesses.
 *
 * A reminder row stores no address. The recipient is derived from trusted
 * provenance, in one order:
 *
 *   1. the message the reminder was created from → its conversation → that
 *      conversation's channel account. This is the strongest link: it is
 *      literally the thread the subscriber asked in, and it satisfies the
 *      product rule that a reminder returns on the channel it came from.
 *
 *   2. failing that (no source message, or the message was deleted — the FK is
 *      nullOnDelete), the subscriber's active accounts on that channel. EXACTLY
 *      one match is required.
 *
 * Zero matches, more than one match, an account that belongs to someone else,
 * an inactive account, or a channel account with no conversation to record the
 * outbound message under: all fail closed. A phone number is never inferred,
 * and a reminder is never delivered to an address the subscriber did not
 * already use on that channel.
 */
final class ReminderRecipientResolver
{
    public function resolve(Reminder $reminder): ?ReminderRecipient
    {
        return $this->fromSourceMessage($reminder) ?? $this->fromSoleActiveAccount($reminder);
    }

    private function fromSourceMessage(Reminder $reminder): ?ReminderRecipient
    {
        if ($reminder->source_message_id === null) {
            return null;
        }

        $conversation = Conversation::query()
            ->with('channelAccount')
            ->whereIn('id', fn ($q) => $q
                ->select('conversation_id')
                ->from('messages')
                ->where('id', $reminder->source_message_id))
            ->first();

        if ($conversation === null) {
            return null;
        }

        $account = $conversation->channelAccount;

        if (! $this->usable($reminder, $account) || $conversation->user_id !== $reminder->user_id) {
            return null;
        }

        return new ReminderRecipient($account, $conversation);
    }

    private function fromSoleActiveAccount(Reminder $reminder): ?ReminderRecipient
    {
        $accounts = ChannelAccount::query()
            ->where('user_id', $reminder->user_id)
            ->where('channel', $reminder->channel->value)
            ->where('status', ChannelAccountStatus::Active->value)
            // Two is enough to know the answer is ambiguous.
            ->limit(2)
            ->get();

        if ($accounts->count() !== 1) {
            return null;
        }

        /** @var ChannelAccount $account */
        $account = $accounts->first();

        // The account decides the address; the conversation only decides where
        // the outbound row is filed, so the newest thread on that same account
        // is a deterministic choice and cannot misdirect the message.
        $conversation = Conversation::query()
            ->where('channel_account_id', $account->id)
            ->where('user_id', $reminder->user_id)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->first();

        if ($conversation === null) {
            return null;
        }

        return new ReminderRecipient($account, $conversation);
    }

    private function usable(Reminder $reminder, ?ChannelAccount $account): bool
    {
        return $account !== null
            && $account->user_id === $reminder->user_id
            && $account->channel === $reminder->channel
            && $account->status === ChannelAccountStatus::Active
            && $account->external_identifier !== '';
    }
}
