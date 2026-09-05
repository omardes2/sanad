<?php

declare(strict_types=1);

namespace App\Data\Finance;

/**
 * One UTC day of the MRR SNAPSHOT HISTORY (Historical MRR Run-rate).
 *
 * A captured day carries the run-rate frozen that day, per currency. A day
 * before the first snapshot is NOT AVAILABLE; a day after it without a
 * snapshot is NOT CAPTURED. Neither is a number, and nothing is interpolated.
 */
final readonly class MrrHistoryDay
{
    public const CAPTURED = 'captured';

    public const NOT_CAPTURED = 'not_captured';

    public const NOT_AVAILABLE = 'not_available';

    /**
     * @param  array<string, array{mrr: string, active: int, trialing: int, past_due: int}>  $byCurrency  empty unless captured
     */
    public function __construct(
        public string $date,
        public string $status,
        public array $byCurrency,
        public ?string $capturedAt,
    ) {}

    public function isCaptured(): bool
    {
        return $this->status === self::CAPTURED;
    }
}
