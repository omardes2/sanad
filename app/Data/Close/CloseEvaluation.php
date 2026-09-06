<?php

declare(strict_types=1);

namespace App\Data\Close;

/**
 * What preflight found for one (month, reporting currency): the seven
 * cash-basis figures (NULL = NOT AVAILABLE), every condition with its
 * verdict, the canonical inputs snapshot and its hash. Blocking conditions
 * make close() refuse; informational ones are recorded with the close.
 * Reconciled Cash Contribution is an internal cash-basis metric — never
 * gross profit, margin, revenue or accounting profit.
 */
final readonly class CloseEvaluation
{
    /**
     * @param  array<string, ?string>  $metrics  keys: gross_cash_collected, refunds, net_cash, gateway_fees, net_cash_after_gateway_fees, reconciled_service_cost, reconciled_cash_contribution
     * @param  list<array{code: string, blocking: bool, detail: string}>  $conditions
     * @param  array<string, mixed>  $snapshot  canonical, deterministic
     */
    public function __construct(
        public string $month,
        public string $reportingCurrency,
        public array $metrics,
        public array $conditions,
        public array $snapshot,
        public string $inputHash,
    ) {}

    /** @return list<string> */
    public function blocking(): array
    {
        return array_values(array_map(static fn (array $c): string => $c['detail'] === '' ? $c['code'] : $c['code'].' ('.$c['detail'].')', array_filter($this->conditions, static fn (array $c): bool => $c['blocking'])));
    }

    public function canClose(): bool
    {
        return $this->blocking() === [];
    }
}
