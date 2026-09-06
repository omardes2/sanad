<?php

declare(strict_types=1);

namespace App\Enums;

/** How a reconciled amount was established (cost_reconciliations.source). */
enum ReconciliationSource: string
{
    /** Σ of explicit evidence allocations from confirmed invoices. */
    case Invoice = 'invoice';

    /** A hand-entered amount backed by an evidence reference. */
    case ManualEvidenced = 'manual_evidenced';

    /** An explicit financial attestation that the actual cost is zero. */
    case ConfirmedZero = 'confirmed_zero';
}
