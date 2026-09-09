<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\DeliverReminder;
use App\Services\Reminders\ReminderDispatcher;
use Illuminate\Console\Command;

class RemindersDispatchCommand extends Command
{
    protected $signature = 'sanad:reminders:dispatch {--limit= : How many due reminders to claim (default reminders.batch)}';

    protected $description = 'Claim due reminders and queue their delivery';

    public function handle(ReminderDispatcher $dispatcher): int
    {
        $limit = (int) ($this->option('limit') ?? config('reminders.batch', 100));
        $claimed = $dispatcher->claimDue($limit);

        // Claiming counts no attempt and sends nothing; the job does the work,
        // carrying the claim identity it must still hold to be allowed to send.
        foreach ($claimed as $claim) {
            DeliverReminder::dispatch($claim->reminderId, $claim->token)->afterCommit();
        }

        $this->info(sprintf('Claimed %d due reminder(s).', count($claimed)));

        return self::SUCCESS;
    }
}
