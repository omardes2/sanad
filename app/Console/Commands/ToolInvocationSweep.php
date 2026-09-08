<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Tools\StaleInvocationSweeper;
use App\Services\Tools\ToolInvocationStore;
use App\Support\Tools\ToolRegistry;
use Illuminate\Console\Command;

/**
 * Console-only recovery (Phase F2): settle READ invocations that a dead process
 * left `running` past their own tool version's timeout.
 *
 * It never retries a tool and never produces a success. The grace is bounded to
 * a non-negative number of seconds here, so no operator can make the sweeper
 * reach back in time.
 */
class ToolInvocationSweep extends Command
{
    protected $signature = 'sanad:tool-invocations-sweep {--limit=50} {--grace=}';

    protected $description = 'Settle read tool invocations left running by a dead process as timed out';

    public function handle(ToolInvocationStore $store, ToolRegistry $registry): int
    {
        $grace = $this->option('grace') === null
            ? StaleInvocationSweeper::GRACE_SECONDS
            : max(0, (int) $this->option('grace'));

        $swept = (new StaleInvocationSweeper($store, $registry, $grace))->sweep((int) $this->option('limit'));

        $this->line('swept:'.$swept);

        return self::SUCCESS;
    }
}
