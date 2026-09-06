<?php

declare(strict_types=1);

namespace App\Enums;

/** The append-only invoice lifecycle vocabulary (cost_invoice_events). */
enum CostInvoiceEventType: string
{
    case Draft = 'draft';

    case Confirmed = 'confirmed';

    case Voided = 'voided';

    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'مسودة',
            self::Confirmed => 'مؤكَّدة',
            self::Voided => 'ملغاة',
            self::Superseded => 'مستبدَلة',
        };
    }
}
