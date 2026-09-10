<?php

declare(strict_types=1);

namespace App\Data\Reminders;

use Carbon\CarbonImmutable;

/**
 * One occurrence the planner says a schedule falls on: its IDENTITY and its
 * INSTANT, which are two different facts and are never conflated.
 *
 * `key` is the nominal wall-clock the subscriber asked for — the thing that does
 * not move. `remindAt` is where that lands in real time today, which can change
 * when a zone changes its rules. The unique index is on the key, so a timezone
 * database update cannot manufacture a second row for the same occurrence.
 *
 * For a nonexistent local time the two disagree on purpose: the key stays
 * `2026-03-28T02:30` while the instant is the 03:30 the clocks actually reached.
 * Keeping the requested identity is what makes re-materialisation idempotent
 * across a rules change.
 */
final readonly class PlannedOccurrence
{
    public function __construct(
        /** The nominal local date, `Y-m-d`. */
        public string $localDate,
        /** The nominal local wall-clock identity, `Y-m-d\TH:i`. */
        public string $key,
        /** The nominal local wall-clock as a comparable value, `Y-m-d H:i:s`. */
        public string $localAt,
        /** Where that wall-clock actually lands, in UTC. */
        public CarbonImmutable $remindAt,
        /** True when the requested local time did not exist and was shifted. */
        public bool $shifted = false,
    ) {}
}
