<?php

declare(strict_types=1);

namespace App\Data\Reconciliation;

/**
 * An explicit share of one invoice line attributed to one monthly
 * reconciliation, expressed in the LINE's currency (signed like the line).
 * When the invoice currency differs from the scope currency the finance user
 * must name the exact fx_rate_id (a quote dated on the invoice's issued_at);
 * nothing is looked up automatically.
 */
final readonly class EvidenceAllocation
{
    public function __construct(public int $costInvoiceLineId, public string $amount, public ?int $fxRateId = null) {}
}
