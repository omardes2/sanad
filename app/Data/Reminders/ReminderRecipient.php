<?php

declare(strict_types=1);

namespace App\Data\Reminders;

use App\Models\ChannelAccount;
use App\Models\Conversation;

/**
 * Where a reminder is delivered, and under which conversation the outbound
 * message is recorded. Both come from trusted server-side provenance — a
 * reminder row never carries an address.
 */
final readonly class ReminderRecipient
{
    public function __construct(
        public ChannelAccount $account,
        public Conversation $conversation,
    ) {}
}
