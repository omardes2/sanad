<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a ledger row's provider cost was obtained. Only ModelPrice and
 * ConfigRate are KNOWN costs; the other two mean "UNPRICED / UNKNOWN COST":
 * the cost columns hold 0 for backward compatibility, but that zero must never
 * be read as "this operation was free". Reports count such rows separately.
 */
enum CostSource: string
{
    /** Costed with a historical model price (model_price_id + pricing_snapshot). */
    case ModelPrice = 'model_price';

    /** Costed with the legacy configurable per-dimension rate (Phase B1). */
    case ConfigRate = 'config_rate';

    /** No applicable price existed at occurred_at (or the model is unknown). */
    case None = 'none';

    /** A price existed but in a different currency than the cost currency. */
    case CurrencyMismatch = 'currency_mismatch';

    public function isKnown(): bool
    {
        return match ($this) {
            self::ModelPrice, self::ConfigRate => true,
            self::None, self::CurrencyMismatch => false,
        };
    }

    /**
     * @return list<string>
     */
    public static function unknownValues(): array
    {
        return [self::None->value, self::CurrencyMismatch->value];
    }

    public function label(): string
    {
        return match ($this) {
            self::ModelPrice => 'سعر نموذج (تاريخي)',
            self::ConfigRate => 'معدل إعدادات',
            self::None => 'غير مسعّر — تكلفة غير معروفة',
            self::CurrencyMismatch => 'اختلاف عملة — تكلفة غير معروفة',
        };
    }
}
