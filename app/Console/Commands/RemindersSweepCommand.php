<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Reminders\ReminderDispatcher;
use Illuminate\Console\Command;

class RemindersSweepCommand extends Command
{
    protected $signature = 'sanad:reminders:sweep';

    protected $description = 'Recover reminders stuck in processing past their claim lease';

    public function handle(ReminderDispatcher $dispatcher): int
    {
        $result = $dispatcher->sweep();

        $this->info(sprintf(
            'Recovered %d reminder(s) for another attempt; %d settled as failed.',
            $result['recovered'],
            $result['failed'],
        ));

        return self::SUCCESS;
    }
}
