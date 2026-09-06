<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a subscription_events row records. Fixed vocabulary: every mutation of
 * a subscription maps to exactly one of these (plus PlanChanged when the plan
 * moved as part of it). Baseline is the one-time "history starts here" marker.
 */
enum SubscriptionEventType: string
{
    /** History start: NULL → the state found at capture time (never an invented past). */
    case Baseline = 'baseline';

    case Activated = 'activated';

    case Suspended = 'suspended';

    case Cancelled = 'cancelled';

    case Extended = 'extended';

    case PlanChanged = 'plan_changed';

    /** Generic transition not covered above (reserved for gateway/system flows). */
    case StatusChanged = 'status_changed';

    public function label(): string
    {
        return match ($this) {
            self::Baseline => 'خط الأساس',
            self::Activated => 'تفعيل',
            self::Suspended => 'إيقاف',
            self::Cancelled => 'إلغاء',
            self::Extended => 'تمديد',
            self::PlanChanged => 'تغيير الباقة',
            self::StatusChanged => 'تغيير الحالة',
        };
    }
}
