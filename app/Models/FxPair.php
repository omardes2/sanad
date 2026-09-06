<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\Payments\ImmutableFinancialRecordException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One canonical FX pair with its official quoting orientation (Phase E3). Identity immutable, never deleted. */
class FxPair extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['pair_key', 'base_currency', 'quote_currency', 'created_by_ref'];

    protected static function booted(): void
    {
        static::updating(static function (self $pair): void {
            foreach (['pair_key', 'base_currency', 'quote_currency'] as $attribute) {
                if ($pair->isDirty($attribute)) {
                    throw ImmutableFinancialRecordException::for($pair, "update of pair identity [{$attribute}]");
                }
            }
        });

        static::deleting(static function (self $pair): void {
            throw ImmutableFinancialRecordException::for($pair, 'delete');
        });
    }

    public static function keyFor(string $a, string $b): string
    {
        return min($a, $b).':'.max($a, $b);
    }

    public function covers(string $a, string $b): bool
    {
        return ($this->base_currency === $a && $this->quote_currency === $b) || ($this->base_currency === $b && $this->quote_currency === $a);
    }

    /** @return HasMany<FxRate, $this> */
    public function rates(): HasMany
    {
        return $this->hasMany(FxRate::class);
    }
}
