<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Reminders\ReminderMaterialiser;
use Illuminate\Console\Command;

class RemindersMaterialiseCommand extends Command
{
    protected $signature = 'sanad:reminders:materialise';

    protected $description = 'Create the occurrence rows active recurring schedules are due, within the configured horizon';

    public function handle(ReminderMaterialiser $materialiser): int
    {
        $result = $materialiser->run();

        $this->info(sprintf(
            'Walked %d schedule(s); created %d occurrence(s).',
            $result['schedules'],
            $result['occurrences'],
        ));

        return self::SUCCESS;
    }
}
