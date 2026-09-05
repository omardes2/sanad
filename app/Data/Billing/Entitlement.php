<?php

declare(strict_types=1);

namespace App\Data\Billing;

/**
 * A subscriber's resolved allowance for one usage dimension under their current
 * plan. null caps mean "unlimited" for that window; entitled=false means the
 * plan does not include the dimension at all (disabled).
 */
final readonly class Entitlement
{
    public function __construct(
        public bool $entitled,
        public ?int $dailyLimit,
        public ?int $monthlyLimit,
        public int $weight,
    ) {}

    public static function disabled(): self
    {
        return new self(false, 0, 0, 1);
    }

    public function dailyUnlimited(): bool
    {
        return $this->dailyLimit === null;
    }

    public function monthlyUnlimited(): bool
    {
        return $this->monthlyLimit === null;
    }
}
