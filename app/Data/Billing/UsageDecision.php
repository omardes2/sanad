<?php

declare(strict_types=1);

namespace App\Data\Billing;

use App\Enums\UsageDimension;
use App\Enums\UsageOutcome;

/**
 * The outcome of a usage check or charge for one dimension.
 */
final readonly class UsageDecision
{
    public function __construct(
        public UsageOutcome $outcome,
        public UsageDimension $dimension,
        public ?string $window = null, // "day" | "month" when a limit was hit
    ) {}

    public static function allow(UsageDimension $dimension): self
    {
        return new self(UsageOutcome::Allowed, $dimension);
    }

    /**
     * True when the capability may be used (fresh allowance or idempotent replay
     * or enforcement disabled).
     */
    public function allowed(): bool
    {
        return in_array($this->outcome, [
            UsageOutcome::Allowed,
            UsageOutcome::AlreadyCharged,
            UsageOutcome::NotEnforced,
        ], true);
    }

    public function limitReached(): bool
    {
        return $this->outcome === UsageOutcome::LimitReached;
    }
}
