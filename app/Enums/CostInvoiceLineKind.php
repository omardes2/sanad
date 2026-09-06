<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Signed line kinds: service / tax / other are >= 0, credit is <= 0;
 * Σ signed lines = the invoice total. Only service and credit lines can be
 * allocated to a reconciliation — tax and other never enter service cost.
 */
enum CostInvoiceLineKind: string
{
    case Service = 'service';

    case Tax = 'tax';

    case Credit = 'credit';

    case Other = 'other';

    public function allowsPositive(): bool
    {
        return $this !== self::Credit;
    }

    public function allowsNegative(): bool
    {
        return $this === self::Credit;
    }

    public function isAllocatable(): bool
    {
        return $this === self::Service || $this === self::Credit;
    }
}
