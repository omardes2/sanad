<?php

declare(strict_types=1);

namespace App\Data\Payments;

use Carbon\CarbonImmutable;

/** What an admin records for a (partial) refund — bounded fields, no free text. */
final readonly class RefundInput
{
    public function __construct(
        public int $customerPaymentId,
        public string $idempotencyKey,
        public string $amount,
        public CarbonImmutable $refundedAt,
        public string $reasonCode,
        public ?string $gatewayRefundRef = null,
        public ?string $evidenceRef = null,
    ) {}
}
