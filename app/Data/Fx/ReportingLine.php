<?php

declare(strict_types=1);

namespace App\Data\Fx;

/**
 * One subject as the reporting view shows it: the original amount and
 * currency always, plus NATIVE (same currency, no rate), CONVERTED (exact
 * fx_rate_id, rate date, direction, frozen target amount) or NOT CONVERTED.
 */
final readonly class ReportingLine
{
    public function __construct(
        public string $subjectType,
        public int $subjectId,
        public string $subjectDate,
        public string $sourceAmount,
        public string $sourceCurrency,
        public string $status,
        public ?string $targetAmount,
        public ?int $fxRateId,
        public ?string $fxRateDate,
        public ?string $rateSnapshot,
        public ?string $direction,
        public ?int $conversionId,
    ) {}

    /** The amount that may enter a reporting-currency total, or null when it may not. */
    public function reportingAmount(): ?string
    {
        return match ($this->status) {
            'NATIVE' => $this->sourceAmount,
            'CONVERTED' => $this->targetAmount,
            default => null,
        };
    }
}
