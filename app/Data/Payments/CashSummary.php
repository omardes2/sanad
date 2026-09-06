<?php

declare(strict_types=1);

namespace App\Data\Payments;

/**
 * Cash figures for one currency and one UTC window (Phase E1). Amounts are
 * decimal strings (scale 2). Gateway fees: when any succeeded payment in the
 * window has an UNKNOWN fee, netAfterFees is NULL and feesUnknownCount > 0 —
 * never a silent zero. The attribution figures are attribution only.
 */
final readonly class CashSummary
{
    public function __construct(
        public string $currency,
        public int $paymentsCount,
        public string $grossCashCollected,
        public int $refundsCount,
        public string $refunds,
        public string $netCash,
        public string $gatewayFeesKnown,
        public int $feesUnknownCount,
        public ?string $netCashAfterGatewayFees,
        public string $allocatedCollectedAmount,
        public string $refundAllocatedAmount,
        public string $netAllocatedAmount,
        public string $unallocatedGrossCollectedAmount,
    ) {}

    public function feesStatus(): string
    {
        return $this->feesUnknownCount > 0 ? 'FEES UNKNOWN' : 'known';
    }
}
