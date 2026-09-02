<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Result of handing an inbound message to the MessageProcessor.
 */
enum ProcessOutcome: string
{
    case Accepted = 'accepted';   // stored + queued for processing
    case Duplicate = 'duplicate'; // external_message_id already seen; ignored
    case Rejected = 'rejected';   // could not be accepted (unknown sender / invalid)
}
