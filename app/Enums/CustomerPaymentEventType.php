<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The official payment lifecycle vocabulary (customer_payment_events) and,
 * by projection, a payment's current_status. E1 records manual payments as
 * created → succeeded; failed / disputed / dispute_resolved are reserved for
 * gateway-driven flows and have no UI yet.
 */
enum CustomerPaymentEventType: string
{
    case Created = 'created';

    case Succeeded = 'succeeded';

    case Failed = 'failed';

    case Disputed = 'disputed';

    case DisputeResolved = 'dispute_resolved';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'أُنشئت',
            self::Succeeded => 'نجحت',
            self::Failed => 'فشلت',
            self::Disputed => 'متنازَع عليها',
            self::DisputeResolved => 'حُسم النزاع',
        };
    }
}
