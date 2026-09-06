<?php

declare(strict_types=1);

namespace App\Enums;

/** Who/what caused a subscription transition. */
enum SubscriptionEventSource: string
{
    case Baseline = 'baseline';

    case Admin = 'admin';

    case Onboarding = 'onboarding';

    case System = 'system';

    /** Reserved for Phase E1+ payment-gateway driven transitions. */
    case Gateway = 'gateway';
}
