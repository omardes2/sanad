<?php

declare(strict_types=1);

namespace App\Data\Reconciliation;

use Carbon\CarbonImmutable;

/** One signed invoice line (service / tax / other >= 0, credit <= 0). */
final readonly class InvoiceLineInput
{
    public function __construct(
        public int $costInvoiceId,
        public int $lineNo,
        public string $kind,
        public string $descriptionCode,
        public string $amount,
        public ?CarbonImmutable $periodStart = null,
        public ?CarbonImmutable $periodEnd = null,
    ) {}
}
