<?php

declare(strict_types=1);

namespace App\Support\Ai;

/**
 * WHICH PHYSICAL ATTEMPT at handling one inbound message is running right now.
 *
 * A logical provider-call position (`call 1`, `call 2`, `call 3` of a turn) can
 * be sent to the provider more than once: the queue retries the whole message
 * after a mid-turn failure, and call 1 is then PHYSICALLY EXECUTED AGAIN and
 * charged again. The logical position identifies where a call sits in the turn;
 * this number identifies which real attempt made it, so both billable requests
 * are recorded instead of the second being deduplicated away.
 *
 * The value comes from the QUEUE, not from a counter of existing rows: it is
 * `$job->attempts()`, which the queue increments each time it reserves the job.
 * `ProcessInboundMessage` is `ShouldBeUnique`, so one attempt of one message
 * runs at a time — there is never a concurrent race to resolve, and no unsafe
 * `MAX(attempt) + 1` anywhere.
 *
 * Outside a queued context (a synchronous call, a test, an artisan run) the
 * attempt is 1.
 */
final class ProviderAttempt
{
    private int $attempt = 1;

    public function set(int $attempt): void
    {
        $this->attempt = max(1, $attempt);
    }

    public function current(): int
    {
        return $this->attempt;
    }
}
