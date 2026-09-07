<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a consent was granted or revoked (Phase F1) — a CLOSED list, never free
 * text. This is what keeps the consent record provably free of personal data:
 * there is no field a name, a phone number or a message can be typed into.
 */
enum ToolConsentReason: string
{
    case SubscriberRequest = 'subscriber_request';

    case OperatorRequest = 'operator_request';

    case SupportRequest = 'support_request';

    case Policy = 'policy';

    case Security = 'security';

    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::SubscriberRequest => 'طلب المشترك',
            self::OperatorRequest => 'طلب المشغّل',
            self::SupportRequest => 'طلب دعم',
            self::Policy => 'سياسة',
            self::Security => 'أمان',
            self::Expired => 'انتهاء صلاحية',
        };
    }
}
