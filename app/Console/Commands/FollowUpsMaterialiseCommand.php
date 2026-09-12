<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\FollowUps\FollowUpAskMaterialiser;
use Illuminate\Console\Command;

/**
 * Advances follow-up loops: creates an ask when one is due, moves the state when
 * reminder truth says an ask left the platform, holds a loop whose approved
 * template is missing, and retires one whose ask budget is spent.
 *
 * It creates and advances ONLY. It never delivers, claims, settles or resolves —
 * an ask is an ordinary reminder, so `sanad:reminders:dispatch` and
 * `sanad:reminders:sweep` do all of that without knowing follow-ups exist.
 */
class FollowUpsMaterialiseCommand extends Command
{
    protected $signature = 'sanad:follow-ups:materialise';

    protected $description = 'Create the follow-up asks that are due and advance loop state';

    public function handle(FollowUpAskMaterialiser $materialiser): int
    {
        $result = $materialiser->run();

        $this->info(sprintf(
            'Follow-ups advanced: %d · asks created: %d',
            $result['follow_ups'],
            $result['asks'],
        ));

        return self::SUCCESS;
    }
}
