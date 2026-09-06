<?php

declare(strict_types=1);

namespace App\Data\Reconciliation;

/** An explicit share of one invoice line attributed to one monthly reconciliation (signed like the line). */
final readonly class EvidenceAllocation
{
    public function __construct(public int $costInvoiceLineId, public string $amount) {}
}
