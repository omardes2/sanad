<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The state a V1 launch gate can be in.
 *
 * Five states, and the distinction between the last two is the whole point:
 * ABSENCE OF EVIDENCE IS NOT EVIDENCE OF FAILURE. A gate we cannot observe
 * (`not_observed`) is honest about not knowing and NEVER blocks the launch on
 * its own; only a gate we can prove is unfinished, misconfigured or waiting on
 * someone outside the team blocks it.
 *
 * `not_implemented` is deliberately separate from `not_ready`: a feature with
 * no code at all is a different conversation from a feature whose configuration
 * is missing, and collapsing them would let "set an env var" and "build the
 * feature" look like the same amount of work on the same screen.
 */
enum LaunchGateState: string
{
    /** Proven satisfied right now. */
    case Ready = 'ready';

    /** Exists but is switched off, misconfigured, or its dependency is down. */
    case NotReady = 'not_ready';

    /** No implementation exists in the codebase. Not a configuration problem. */
    case NotImplemented = 'not_implemented';

    /** Waiting on a party outside the team (Meta template approval, the bank). */
    case BlockedExternal = 'blocked_external';

    /**
     * We cannot prove either way from what the platform durably records.
     * NEVER a launch blocker — see the class docblock.
     */
    case NotObserved = 'not_observed';

    /**
     * Does this state stop V1 from launching, for a gate that V1 requires?
     *
     * `NotObserved` returns false ON PURPOSE. "No reminder has been delivered
     * recently" can mean the scheduler is broken, or simply that nothing was
     * due — and a readiness screen that cannot tell the difference must not
     * declare the launch blocked from silence.
     */
    public function blocksLaunch(): bool
    {
        return match ($this) {
            self::NotReady, self::NotImplemented, self::BlockedExternal => true,
            self::Ready, self::NotObserved => false,
        };
    }

    public function isReady(): bool
    {
        return $this === self::Ready;
    }

    public function label(): string
    {
        return match ($this) {
            self::Ready => 'جاهز',
            self::NotReady => 'غير جاهز',
            self::NotImplemented => 'غير منفَّذ',
            self::BlockedExternal => 'محجوب خارجيًا',
            self::NotObserved => 'غير مرصود',
        };
    }

    /** Tailwind tone for the badge — presentation only, no authority. */
    public function tone(): string
    {
        return match ($this) {
            self::Ready => 'emerald',
            self::NotReady, self::NotImplemented => 'rose',
            self::BlockedExternal => 'amber',
            self::NotObserved => 'slate',
        };
    }
}
