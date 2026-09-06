<?php

declare(strict_types=1);

namespace App\Data\Payments;

use Carbon\CarbonImmutable;

/** What an admin records for a manual payment — bounded fields, no free text. */
final readonly class ManualPaymentInput
{
    public function __construct(
        public int $subscriberId,
        public string $idempotencyKey,
        public string $amount,
        public string $currency,
        public CarbonImmutable $receivedAt,
        public ?string $gatewayPaymentRef = null,
        public ?string $gatewayFeeAmount = null,
        public ?string $feeCurrency = null,
        public ?string $reference = null,
        public ?string $reasonCode = null,
        public ?string $evidenceRef = null,
    ) {}
}
