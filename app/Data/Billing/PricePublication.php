<?php

declare(strict_types=1);

namespace App\Data\Billing;

use App\Enums\ModelPriceSource;
use App\Support\Billing\DecimalMath;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Everything PriceBook needs to open a new price period. Rates are decimal
 * STRINGS per million tokens (max 8 fractional digits) — validated here so a
 * malformed or negative amount never reaches the database.
 */
final readonly class PricePublication
{
    public function __construct(
        public string $currency,
        public string $inputPerMillion,
        public string $outputPerMillion,
        public ?string $cachedInputPerMillion,
        public string $perRequest,
        public CarbonImmutable $effectiveFrom,
        public ?CarbonImmutable $effectiveUntil = null,
        public string $unit = 'token',
        public ModelPriceSource $source = ModelPriceSource::Manual,
        public ?string $note = null,
        public ?int $createdBy = null,
    ) {
        if (preg_match('/^[A-Za-z]{3}$/', $this->currency) !== 1) {
            throw new InvalidArgumentException("Invalid currency [{$this->currency}] (ISO 4217, 3 letters).");
        }

        foreach (['inputPerMillion', 'outputPerMillion', 'perRequest'] as $field) {
            DecimalMath::toScaled($this->{$field}, 8); // throws on invalid/negative/too precise
        }

        if ($this->cachedInputPerMillion !== null) {
            DecimalMath::toScaled($this->cachedInputPerMillion, 8);
        }

        if ($this->effectiveUntil !== null && $this->effectiveUntil <= $this->effectiveFrom) {
            throw new InvalidArgumentException('effective_until must be after effective_from.');
        }

        if (! in_array($this->unit, ['token', 'request', 'minute', 'image'], true)) {
            throw new InvalidArgumentException("Unknown price unit [{$this->unit}].");
        }
    }
}
