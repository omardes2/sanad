<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The current state of one (subscriber, capability) consent (Phase F1).
 * NO ROW is not a third state: it reads as NOT GRANTED, and only an explicit
 * grant moves it — consent is never implied, defaulted or inherited.
 */
enum ToolConsentStatus: string
{
    case Granted = 'granted';

    case Revoked = 'revoked';

    /** The state of a capability with no consent row at all. */
    public const NOT_GRANTED = 'not_granted';

    public function isGranted(): bool
    {
        return $this === self::Granted;
    }

    public function label(): string
    {
        return match ($this) {
            self::Granted => 'ممنوحة',
            self::Revoked => 'مسحوبة',
        };
    }
}
