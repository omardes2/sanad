<?php

declare(strict_types=1);

namespace App\Data\Reconciliation;

use Carbon\CarbonImmutable;

/** What an admin records for a supplier invoice draft — bounded fields, no free text, no PII. */
final readonly class CostInvoiceInput
{
    public function __construct(
        public string $component,
        public string $counterpartyKey,
        public string $idempotencyKey,
        public CarbonImmutable $issuedAt,
        public CarbonImmutable $periodStart,
        public CarbonImmutable $periodEnd,
        public string $currency,
        public string $totalAmount,
        public ?string $invoiceRef = null,
        public ?string $evidenceRef = null,
    ) {}
}
