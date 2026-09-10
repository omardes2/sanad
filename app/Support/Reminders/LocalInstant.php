<?php

declare(strict_types=1);

namespace App\Support\Reminders;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Turns a NOMINAL LOCAL WALL-CLOCK into a real instant, honestly, in the two
 * cases where a wall-clock does not map onto one.
 *
 * WHY THIS IS ITS OWN CLASS. A recurring reminder is a promise about a WALL
 * CLOCK: «كل يوم الساعة ٩» means nine in the morning on both sides of a daylight
 * transition, which a fixed UTC offset cannot express — it drifts by the gap
 * twice a year and starts firing at eight or at ten. So every occurrence resolves
 * its own nominal local time through the zone's rules AT MATERIALISATION, and
 * this is the single place that knows how.
 *
 * ── THE SPRING GAP ───────────────────────────────────────────────────────────
 * When the clocks jump forward, a local time can simply not exist: in
 * Asia/Hebron there is no 02:30 on the transition day. The rule here is SHIFT
 * FORWARD BY THE ACTUAL GAP, so 02:30 becomes 03:30 and the requested minute
 * survives — the subscriber asked for half past, and half past is what they get.
 *
 * The gap is READ FROM THE IANA RULES for the transition in question, never
 * assumed to be an hour. Australia/Lord_Howe shifts by THIRTY MINUTES, so there
 * a nominal 02:15 resolves to 02:45; a hard-coded hour would have produced 03:15,
 * which is a time the subscriber never asked for on a day they never noticed.
 *
 * ── THE AUTUMN OVERLAP ───────────────────────────────────────────────────────
 * When the clocks go back, a local time happens TWICE. The rule is the FIRST of
 * the two, deterministically — one delivery, at the earlier instant.
 *
 * That has to be written out explicitly, because PHP's own normalisation picks
 * the SECOND: constructing `2026-10-25 02:30` in Europe/Berlin yields 01:30 UTC,
 * the post-transition instant, not 00:30 UTC. Inheriting that default would have
 * been a silent, untested choice of the wrong one.
 */
final class LocalInstant
{
    /**
     * Resolve `Y-m-d H:i` in $timezone to a UTC instant.
     *
     * @param  string  $nominal  a local wall-clock, `2026-09-11 09:00`
     */
    public static function resolve(string $nominal, string $timezone): CarbonImmutable
    {
        $zone = new DateTimeZone($timezone);
        $candidate = new DateTimeImmutable($nominal, $zone);

        // A nonexistent local time: PHP has already moved it, so compare what it
        // renders back as against what was asked for.
        if ($candidate->format('Y-m-d H:i') !== $nominal) {
            return self::shiftByGap($nominal, $zone);
        }

        return self::earliestOf($nominal, $zone, $candidate);
    }

    /**
     * Did this nominal local time not exist in this zone?
     *
     * Exposed so the planner and the tests can state the case by name instead of
     * inferring it from a resolved instant.
     */
    public static function isNonexistent(string $nominal, string $timezone): bool
    {
        $zone = new DateTimeZone($timezone);

        return (new DateTimeImmutable($nominal, $zone))->format('Y-m-d H:i') !== $nominal;
    }

    /** Does this nominal local time occur twice in this zone? */
    public static function isAmbiguous(string $nominal, string $timezone): bool
    {
        $zone = new DateTimeZone($timezone);
        $candidate = new DateTimeImmutable($nominal, $zone);

        if ($candidate->format('Y-m-d H:i') !== $nominal) {
            return false;   // It does not occur even once.
        }

        return self::earlierTwin($nominal, $zone, $candidate) !== null;
    }

    /**
     * The nonexistent case: take the requested wall-clock and add the REAL gap
     * of the transition that swallowed it.
     *
     * Adding the gap to the nominal time — rather than snapping to the first
     * valid instant — is what preserves the minute. 02:30 with a one-hour gap is
     * 03:30, and with Lord Howe's half-hour gap it is 02:45.
     */
    private static function shiftByGap(string $nominal, DateTimeZone $zone): CarbonImmutable
    {
        $gap = self::gapSeconds($nominal, $zone);

        if ($gap <= 0) {
            // No transition could be identified. Fall back to whatever the zone
            // rules already resolved it to rather than inventing an offset: a
            // guessed instant is worse than the platform's own normalisation.
            return CarbonImmutable::instance(new DateTimeImmutable($nominal, $zone))->utc();
        }

        /*
         * The shift is computed on a UTC carrier, NOT on a zone-aware value.
         * `DateTime::modify()` with a relative interval does WALL-CLOCK
         * arithmetic, so asking a zone-aware datetime for "+3600 seconds" across
         * a transition moves the clock face by an hour rather than the instant —
         * which is precisely the confusion this class exists to remove. On a UTC
         * carrier the two are the same thing.
         */
        $shifted = (new DateTimeImmutable($nominal.':00', new DateTimeZone('UTC')))
            ->modify('+'.$gap.' seconds')
            ->format('Y-m-d H:i');

        // Re-resolve from the SHIFTED wall clock, so the answer is a real local
        // time in its own right rather than an instant reached by arithmetic.
        $resolved = new DateTimeImmutable($shifted, $zone);

        return CarbonImmutable::instance($resolved)->utc();
    }

    /**
     * The size of the forward jump that made $nominal nonexistent, in seconds,
     * taken from the zone's own transition table.
     */
    private static function gapSeconds(string $nominal, DateTimeZone $zone): int
    {
        // A window wide enough to contain the transition on either side of the
        // nominal day, whatever the offset.
        $approx = (new DateTimeImmutable($nominal, $zone))->getTimestamp();
        $transitions = $zone->getTransitions($approx - 172800, $approx + 172800);

        $previous = null;

        foreach ($transitions as $transition) {
            if ($previous !== null && $transition['offset'] > $previous['offset']) {
                return $transition['offset'] - $previous['offset'];
            }

            $previous = $transition;
        }

        return 0;
    }

    /**
     * For an ambiguous local time, the EARLIER of the two instants; otherwise
     * the instant itself.
     */
    private static function earliestOf(string $nominal, DateTimeZone $zone, DateTimeImmutable $candidate): CarbonImmutable
    {
        $earlier = self::earlierTwin($nominal, $zone, $candidate);

        return CarbonImmutable::instance($earlier ?? $candidate)->utc();
    }

    /**
     * The earlier instant that renders to the same local time, if one exists.
     *
     * Probed against the real backward transition rather than a guessed hour,
     * for the same reason the gap is: a zone that falls back by thirty minutes
     * would otherwise be mishandled.
     */
    private static function earlierTwin(string $nominal, DateTimeZone $zone, DateTimeImmutable $candidate): ?DateTimeImmutable
    {
        $timestamp = $candidate->getTimestamp();
        $transitions = $zone->getTransitions($timestamp - 172800, $timestamp + 172800);
        $previous = null;

        foreach ($transitions as $transition) {
            if ($previous !== null && $transition['offset'] < $previous['offset']) {
                $back = $previous['offset'] - $transition['offset'];

                /*
                 * ABSOLUTE arithmetic, via the epoch — never
                 * `$candidate->modify('-N seconds')`. On a zone-aware value that
                 * modify does WALL-CLOCK arithmetic: across a fall-back it moves
                 * 02:30 to 01:30 on the clock face (7200 real seconds) instead of
                 * stepping back 3600 real seconds to the earlier 02:30. Building
                 * from the timestamp and then applying the zone is the only way
                 * to ask the question that is actually being asked.
                 */
                $earlier = (new DateTimeImmutable('@'.($timestamp - $back)))->setTimezone($zone);

                if ($earlier->format('Y-m-d H:i') === $nominal) {
                    return $earlier;
                }
            }

            $previous = $transition;
        }

        return null;
    }
}
