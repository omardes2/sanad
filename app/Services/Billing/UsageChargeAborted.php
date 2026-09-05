<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\UsageOutcome;
use RuntimeException;

/**
 * Internal control-flow signal used to roll back a charge transaction cleanly
 * (a hit limit, or a concurrent idempotent replay). Never leaves the engine —
 * charge() converts it into a UsageDecision. Throwing forces DB::transaction to
 * ROLLBACK, which is the only statement issued after a caught unique violation,
 * so the PostgreSQL transaction is never used again while aborted.
 */
final class UsageChargeAborted extends RuntimeException
{
    public function __construct(
        public readonly UsageOutcome $outcome,
        public readonly ?string $window = null,
    ) {
        parent::__construct($outcome->value);
    }
}
