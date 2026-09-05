<?php

declare(strict_types=1);

use App\Support\Security\SecretString;

it('reveals only through reveal() and masks every other exit', function () {
    $secret = new SecretString('gsk_UNIT_SECRET_VALUE_1234');

    expect($secret->reveal())->toBe('gsk_UNIT_SECRET_VALUE_1234')
        ->and((string) $secret)->toBe(SecretString::MASK)
        ->and("Bearer {$secret}")->toBe('Bearer [secret]')
        ->and(json_encode(['k' => $secret]))->toBe('{"k":"[secret]"}')
        ->and(print_r($secret, true))->not->toContain('UNIT_SECRET')
        ->and($secret->fingerprint())->toMatch('/^[0-9a-f]{16}$/')
        ->and($secret->fingerprint())->toBe(SecretString::fingerprintOf('gsk_UNIT_SECRET_VALUE_1234'))
        ->and($secret->last4())->toBe('1234')
        ->and($secret->isEmpty())->toBeFalse()
        ->and((new SecretString('  '))->isEmpty())->toBeTrue()
        ->and($secret->equals(new SecretString('gsk_UNIT_SECRET_VALUE_1234')))->toBeTrue()
        ->and($secret->equals(new SecretString('other')))->toBeFalse();

    ob_start();
    var_dump($secret);
    $dump = (string) ob_get_clean();

    expect($dump)->not->toContain('UNIT_SECRET')
        ->and(fn () => serialize($secret))->toThrow(LogicException::class);
});
