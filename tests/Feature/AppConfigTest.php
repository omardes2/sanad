<?php

declare(strict_types=1);

it('is named SANAD', function () {
    expect(config('app.name'))->toBe('SANAD')
        ->and(config('sanad.name'))->toBe('SANAD');
});

it('defaults to Arabic with English fallback', function () {
    expect(config('app.locale'))->toBe('ar')
        ->and(config('app.fallback_locale'))->toBe('en');
});

it('stores time internally in UTC', function () {
    expect(config('app.timezone'))->toBe('UTC');
});

it('defaults the user timezone to Asia/Hebron', function () {
    expect(config('sanad.default_timezone'))->toBe('Asia/Hebron');
});

it('defaults the currency to ILS', function () {
    expect(config('sanad.default_currency'))->toBe('ILS');
});
