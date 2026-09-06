<?php

declare(strict_types=1);

namespace App\Data\Fx;

/**
 * A reporting-currency total: a number ONLY when every required line is
 * NATIVE or has a valid frozen conversion to the target; otherwise
 * INCOMPLETE / NOT AVAILABLE with the count of lines that are not converted.
 * Original currencies are never summed together.
 */
final readonly class ReportingTotal
{
    public function __construct(
        public string $label,
        public string $targetCurrency,
        public ?string $amount,
        public int $lines,
        public int $native,
        public int $converted,
        public int $notConverted,
    ) {}

    public function status(): string
    {
        return $this->amount === null ? 'INCOMPLETE / NOT AVAILABLE' : 'complete (converted at recorded rates)';
    }
}
