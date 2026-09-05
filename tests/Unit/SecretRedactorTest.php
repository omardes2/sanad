<?php

declare(strict_types=1);

use App\Support\Security\SecretRedactor;
use App\Support\Security\SensitiveFieldRegistry;

function redactor(): SecretRedactor
{
    return new SecretRedactor(new SensitiveFieldRegistry);
}

it('redacts explicitly registered keys and leaves other keys alone', function () {
    $out = redactor()->redact([
        'api_key' => 'abc123456',
        'access_token' => 'EAAB'.str_repeat('x', 30),
        'password' => 'p@ss',
        'name' => 'Omar',
        'count' => 3,
    ]);

    expect($out['api_key'])->toStartWith('[REDACTED:')
        ->and($out['access_token'])->toStartWith('[REDACTED:')
        ->and($out['password'])->toStartWith('[REDACTED:')
        ->and($out['name'])->toBe('Omar')
        ->and($out['count'])->toBe(3);
});

it('walks nested arrays and keeps null / empty values as they are', function () {
    $out = redactor()->redact(['provider' => ['config' => ['api_key' => 'k1', 'base_url' => 'https://x'], 'secret' => null, 'token' => '']]);

    expect($out['provider']['config']['api_key'])->toStartWith('[REDACTED:')
        ->and($out['provider']['config']['base_url'])->toBe('https://x')
        ->and($out['provider']['secret'])->toBeNull()
        ->and($out['provider']['token'])->toBe('');
});

it('uses a name pattern as a defensive layer for unregistered keys', function () {
    $out = redactor()->redact(['x_client_secret' => 'abc', 'signing_key' => 'def', 'stripe_passwd' => 'ghi', 'description' => 'no']);

    expect($out['x_client_secret'])->toStartWith('[REDACTED:')
        ->and($out['signing_key'])->toStartWith('[REDACTED:')
        ->and($out['stripe_passwd'])->toStartWith('[REDACTED:')
        ->and($out['description'])->toBe('no');
});

it('recognises vendor secret value shapes under any key', function () {
    $r = redactor();

    expect($r->redact('sk-proj-abcdefghijklmnop'))->toStartWith('[REDACTED:')
        ->and($r->redact('gsk_abcdefghijklmnop'))->toStartWith('[REDACTED:')
        ->and($r->redact('Bearer abcdefghijklmnop'))->toStartWith('[REDACTED:')
        ->and($r->redact("-----BEGIN PRIVATE KEY-----\nabc"))->toStartWith('[REDACTED:')
        ->and($r->redact('just a sentence'))->toBe('just a sentence');
});

it('masks deterministically so two audit rows can show that a secret changed without revealing it', function () {
    $r = redactor();

    expect($r->mask('same'))->toBe($r->mask('same'))
        ->and($r->mask('same'))->not->toBe($r->mask('other'))
        ->and(strlen($r->mask('anything')))->toBe(strlen('[REDACTED:12345678]'));
});

it('lets the explicit registry grow at runtime', function () {
    $registry = new SensitiveFieldRegistry;
    $registry->registerKeys(['wa_phone_pin']);
    $r = new SecretRedactor($registry);

    expect($r->redact(['wa_phone_pin' => '123456'])['wa_phone_pin'])->toStartWith('[REDACTED:');
});
