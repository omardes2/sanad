<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Enums\UsageDimension;
use App\Models\Message;

/**
 * Builds the two identifiers every ledger row carries:
 *
 *  - correlation_id  — the LOGICAL request all related billable work belongs to
 *                      (an inbound message today; a job or workflow later).
 *  - idempotency_key — ONE billable invocation within it. A retry of the same
 *                      invocation reuses the key (recorded once); a genuinely
 *                      new invocation for the same request — a fallback
 *                      provider call, a second AI round after a tool result, a
 *                      transcription — gets a different sequence number, so the
 *                      unique key never blocks legitimate multiple charges for
 *                      one message.
 */
final class UsageKeys
{
    public static function correlationForMessage(Message|int $message): string
    {
        $id = $message instanceof Message ? $message->id : $message;

        return "message:{$id}";
    }

    public static function invocation(UsageDimension|string $operation, string $correlationId, int $sequence = 1): string
    {
        $operation = $operation instanceof UsageDimension ? $operation->value : $operation;

        return "{$operation}:{$correlationId}#{$sequence}";
    }
}
