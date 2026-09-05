<?php

declare(strict_types=1);

namespace App\Data\Billing;

use App\Models\UsageEvent;

/**
 * Result of UsageRecorder::record(): the ledger row for this invocation and
 * whether THIS call created it (false = an idempotent replay of an existing row).
 */
final readonly class RecordResult
{
    public function __construct(
        public UsageEvent $event,
        public bool $created,
    ) {}
}
