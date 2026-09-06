<?php

declare(strict_types=1);

use App\Enums\FxDirection;
use App\Exceptions\Fx\FxRuleException;
use App\Support\Fx\FxMath;

/**
 * Phase E3 — exact FX arithmetic: scale-12 rates, one half-up rounding at the
 * target scale, direct = multiply, inverse = divide (no reciprocal rounding),
 * no floats, identical on any engine (pure PHP big decimals).
 */
it('multiplies for direct and divides for inverse with one half-up rounding at the target scale', function () {
    expect(FxMath::convert('100.00', 2, '3.650000000000', FxDirection::Direct, 2))->toBe('365.00')
        ->and(FxMath::convert('365.00', 2, '3.650000000000', FxDirection::Inverse, 2))->toBe('100.00')
        ->and(FxMath::convert('10.00', 2, '3.650000000000', FxDirection::Inverse, 2))->toBe('2.74') // 2.7397260273…
        ->and(FxMath::convert('10.00', 2, '3.000000000000', FxDirection::Inverse, 2))->toBe('3.33')
        ->and(FxMath::convert('100.00', 2, '3.700000000000', FxDirection::Inverse, 6))->toBe('27.027027')
        ->and(FxMath::convert('1000.000000', 6, '3.654321987654', FxDirection::Direct, 6))->toBe('3654.321988') // 3654.321987654 → half-up
        ->and(FxMath::convert('0.01', 2, '0.500000000000', FxDirection::Direct, 2))->toBe('0.01') // 0.005 → up
        ->and(FxMath::convert('0.01', 2, '0.400000000000', FxDirection::Direct, 2))->toBe('0.00') // 0.004 → down
        ->and(FxMath::convert('0.03', 2, '0.500000000000', FxDirection::Direct, 2))->toBe('0.02') // 0.015 → up (not banker's)
        ->and(FxMath::convert('1.000000', 6, '3.000000000000', FxDirection::Inverse, 6))->toBe('0.333333')
        ->and(FxMath::convert('2.000000', 6, '3.000000000000', FxDirection::Inverse, 6))->toBe('0.666667')
        ->and(FxMath::convert('123456789.99', 2, '3.650000000000', FxDirection::Direct, 2))->toBe('450617283.46'); // beyond int64 products, still exact
});

it('does not round the reciprocal: dividing by the rate differs from multiplying by a rounded reciprocal', function () {
    // 1/3.65 = 0.27397260273972… — a reciprocal rounded to 12 places then multiplied would drift on large amounts.
    expect(FxMath::convert('1000000.00', 2, '3.650000000000', FxDirection::Inverse, 2))->toBe('273972.60')
        ->and(FxMath::convert('999999.99', 2, '3.650000000000', FxDirection::Inverse, 6))->toBe('273972.600000');
});

it('refuses an amount that does not fit its declared scale and a non-positive rate', function () {
    expect(fn () => FxMath::convert('10.005', 2, '3.65', FxDirection::Direct, 2))->toThrow(FxRuleException::class)
        ->and(fn () => FxMath::convert('10.00', 2, '0', FxDirection::Direct, 2))->toThrow(FxRuleException::class)
        ->and(fn () => FxMath::rateToScaled('0.0000000000001'))->toThrow(FxRuleException::class)
        ->and(FxMath::rateToScaled('3.65'))->toBe(3650000000000)
        ->and(FxMath::directionFor('USD', 'ILS', 'USD', 'ILS'))->toBe(FxDirection::Direct)
        ->and(FxMath::directionFor('USD', 'ILS', 'ILS', 'USD'))->toBe(FxDirection::Inverse)
        ->and(FxMath::directionFor('USD', 'ILS', 'EUR', 'ILS'))->toBeNull()
        ->and(FxMath::formatAtScale('365.000000', 2))->toBe('365.00');
});
