<?php

declare(strict_types=1);

namespace App\Data\Reconciliation;

/**
 * A reconciliation request for one scope (component, counterparty, calendar
 * month UTC, currency). `expectedCurrentReconciliationId` is what the caller
 * saw as the scope's current pointer (null = "no reconciliation yet"); a
 * mismatch is stale, never last-writer-wins.
 *
 *  - source `invoice`: `allocations` non-empty, reconciled amount = Σ of them;
 *  - source `manual_evidenced`: `reconciledAmount` + evidenceRef + reasonCode;
 *  - source `confirmed_zero`: typedConfirmation === 'ZERO' + evidenceRef + reasonCode.
 *
 * @param  list<EvidenceAllocation>  $allocations
 */
final readonly class ReconciliationInput
{
    public function __construct(
        public string $component,
        public string $counterpartyKey,
        public string $month,
        public string $currency,
        public ?int $expectedCurrentReconciliationId,
        public string $source,
        public array $allocations = [],
        public ?string $reconciledAmount = null,
        public ?string $reasonCode = null,
        public ?string $evidenceRef = null,
        public ?string $typedConfirmation = null,
    ) {}
}
