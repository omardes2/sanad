<?php

declare(strict_types=1);

namespace App\Data\Fx;

/**
 * Freeze a reporting conversion of one subject with an EXPLICIT rate id.
 * expectedCurrentConversionId: the revision the caller saw for
 * (subject, purpose, target) — null = none yet; mismatch = stale.
 */
final readonly class ReportingConversionInput
{
    public function __construct(
        public string $subjectType,
        public int $subjectId,
        public string $targetCurrency,
        public int $fxRateId,
        public ?int $expectedCurrentConversionId = null,
        public ?string $reasonCode = null,
    ) {}
}
