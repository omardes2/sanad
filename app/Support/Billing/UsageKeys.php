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
 *
 * Determinism contract: both identifiers are PURE functions of their inputs.
 * The sequence number is assigned by the caller from the STRUCTURE of the
 * request (e.g. round index, fallback attempt index) — never derived from
 * counting existing rows. So a retry of the same invocation always reproduces
 * the same key, a genuinely new invocation gets a different but stable key,
 * and two concurrent workers handling the same invocation can never compute
 * two different keys for it.
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

    /**
     * ONE PHYSICAL provider request.
     *
     * `$call` is the logical position in the turn (call 1, 2 or 3) and `$attempt`
     * is which real attempt at the message made it — the queue's own attempt
     * number, never a count of existing rows. The two together are what the
     * PROVIDER actually charged for: a queue retry re-sends call 1, and that
     * second request gets its own key and its own ledger row instead of being
     * deduplicated into the first.
     *
     * Quota is a different question and keeps using invocation() above: a
     * subscriber's allowance is consumed once per user-facing reply, however
     * many physical requests it took to produce.
     */
    public static function providerAttempt(UsageDimension|string $operation, string $correlationId, int $call, int $attempt): string
    {
        $operation = $operation instanceof UsageDimension ? $operation->value : $operation;

        return "{$operation}:{$correlationId}:call:{$call}:attempt:{$attempt}";
    }
}
