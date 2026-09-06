<?php

declare(strict_types=1);

namespace App\Data\Fx;

/**
 * A manual point-in-time quote: 1 base = rate × quote on rate_date, in the
 * pair's OFFICIAL orientation (a reversed entry is refused, never silently
 * flipped). expectedCurrentRateId is the revision the caller saw for
 * (pair, date): null = "no quote yet"; a mismatch is stale.
 */
final readonly class RecordRateInput
{
    public function __construct(
        public string $baseCurrency,
        public string $quoteCurrency,
        public string $rateDate,
        public string $rate,
        public string $evidenceRef,
        public ?int $expectedCurrentRateId = null,
        public ?string $reasonCode = null,
    ) {}
}
