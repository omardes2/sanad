<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Reminders\ReminderDispatcher;
use App\Support\SafeError;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers ONE already-claimed reminder.
 *
 * `tries = 1` on purpose. The retry policy for a reminder lives in the sweeper,
 * where it is counted against the two-attempt ceiling and visible in the row —
 * not in the queue, where a silent re-run would be an unsolicited message
 * nobody counted. (`ReminderDispatcher::authoriseDispatch()` would refuse such
 * a re-run anyway; this simply states the intent.)
 *
 * A job that dies leaves the reminder `processing`, which the sweeper recovers
 * with full knowledge of whether a request was ever authorised.
 */
class DeliverReminder implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 300;

    public function __construct(public int $reminderId)
    {
        $this->onQueue('reminders');
    }

    public function uniqueId(): string
    {
        return 'deliver-reminder:'.$this->reminderId;
    }

    public function handle(ReminderDispatcher $dispatcher): void
    {
        $dispatcher->deliver($this->reminderId);
    }

    public function failed(?Throwable $exception): void
    {
        // Identifiers only — never the title, the number or the body.
        Log::warning('sanad.reminder.job_failed', [
            'reminder_id' => $this->reminderId,
            'error' => SafeError::summarize($exception),
        ]);
    }
}
