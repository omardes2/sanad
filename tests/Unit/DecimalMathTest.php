<?php

declare(strict_types=1);

use App\Support\Billing\DecimalMath;

it('parses decimal strings exactly at a scale', function () {
    expect(DecimalMath::toScaled('0.15', 8))->toBe(15000000)
        ->and(DecimalMath::toScaled('2', 8))->toBe(200000000)
        ->and(DecimalMath::toScaled('0', 8))->toBe(0)
        ->and(DecimalMath::toScaled('0.00000001', 8))->toBe(1)
        ->and(DecimalMath::toScaled('12.5', 2))->toBe(1250);
});

it('rejects negative, malformed and over-precise decimals', function () {
    expect(fn () => DecimalMath::toScaled('-1', 8))->toThrow(InvalidArgumentException::class)
        ->and(fn () => DecimalMath::toScaled('abc', 8))->toThrow(InvalidArgumentException::class)
        ->and(fn () => DecimalMath::toScaled('0.123456789', 8))->toThrow(InvalidArgumentException::class)
        ->and(fn () => DecimalMath::toScaled('', 8))->toThrow(InvalidArgumentException::class);
});

it('formats and rescales with round-half-up', function () {
    expect(DecimalMath::format(1500000, 6))->toBe('1.500000')
        ->and(DecimalMath::format(5, 6))->toBe('0.000005')
        ->and(DecimalMath::format(0, 6))->toBe('0.000000')
        ->and(DecimalMath::rescale(15, 8, 6))->toBe(0) // 0.00000015 → 0.000000
        ->and(DecimalMath::rescale(50, 8, 6))->toBe(1) // 0.00000050 → 0.000001 (half up)
        ->and(DecimalMath::rescale(149, 8, 6))->toBe(1)
        ->and(DecimalMath::rescale(150, 8, 6))->toBe(2)
        ->and(DecimalMath::rescale(7, 2, 6))->toBe(70000);
});
