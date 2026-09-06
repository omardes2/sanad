<?php

declare(strict_types=1);

namespace App\Data\Finance;

/**
 * MRR SNAPSHOT HISTORY over a UTC window: a run-rate series, NOT a revenue
 * series. The days are never summed, multiplied by day counts, or combined
 * with usage cost — that would be revenue accounting, which needs Phase E.
 */
final readonly class MrrHistorySeries
{
    /**
     * @param  list<MrrHistoryDay>  $days  oldest first, one entry per UTC day of the window
     * @param  list<string>  $currencies  currencies seen in captured days (markers excluded), sorted
     */
    public function __construct(
        public string $from,
        public string $to,
        public ?string $firstSnapshotDate,
        public array $days,
        public array $currencies,
    ) {}

    public function hasAnySnapshot(): bool
    {
        return $this->firstSnapshotDate !== null;
    }

    /**
     * @return array{captured: int, not_captured: int, not_available: int}
     */
    public function counts(): array
    {
        $counts = ['captured' => 0, 'not_captured' => 0, 'not_available' => 0];

        foreach ($this->days as $day) {
            $counts[$day->status]++;
        }

        return $counts;
    }
}
