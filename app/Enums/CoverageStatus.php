<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a cost component's KNOWN figure covers everything that happened in
 * a window. Anything but Complete means "the known cost is NOT the full
 * service cost" — the dashboard must say so instead of showing a total.
 */
enum CoverageStatus: string
{
    /** Every relevant row in the window carries a known cost for this component. */
    case Complete = 'complete';

    /** Usage exists whose cost for this component is unknown or not recorded. */
    case Incomplete = 'incomplete';

    /** Nothing in the platform produces this component yet: a zero is absence of data, not a cost. */
    case NoProducer = 'no_producer';

    /** No usage in the window could have generated this component. */
    case NotApplicable = 'not_applicable';

    public function isComplete(): bool
    {
        return $this === self::Complete || $this === self::NotApplicable;
    }

    public function label(): string
    {
        return match ($this) {
            self::Complete => 'مكتملة',
            self::Incomplete => 'غير مكتملة',
            self::NoProducer => 'لا مصدر بعد',
            self::NotApplicable => 'لا ينطبق',
        };
    }
}
