<?php

declare(strict_types=1);

namespace App\Enums;

/** Who/what recorded a payment fact or lifecycle event. */
enum PaymentSource: string
{
    /** An admin recorded it by hand (Phase E1 — the only source). */
    case Manual = 'manual';

    /** Reserved: a live gateway integration. */
    case Gateway = 'gateway';

    case System = 'system';
}
