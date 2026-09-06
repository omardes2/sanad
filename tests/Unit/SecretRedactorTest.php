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

it('masks payment-card and bank identifiers by key (Phase E1) while harmless payment fields stay readable', function () {
    $out = redactor()->redact([
        'pan' => '4111111111111111', 'card_number' => '4111 1111 1111 1111', 'cardNumber' => '4111', 'cvv' => '123', 'cvc' => '456',
        'iban' => 'PS92PALS000000000400123456702', 'account_number' => '0012345', 'bank.iban' => 'x', 'customer_pan' => 'y',
        'card_brand' => 'visa', 'reference' => 'BANK-2026-09-05', 'gateway_payment_ref' => 'EXT-777', 'reason_code' => 'bank_transfer',
        'amount' => '49.90', 'currency' => 'USD', 'company' => 'Sanad', 'span' => 'keep', 'expand' => 'keep',
    ]);

    foreach (['pan', 'card_number', 'cardNumber', 'cvv', 'cvc', 'iban', 'account_number', 'bank.iban', 'customer_pan'] as $key) {
        expect($out[$key])->toStartWith('[REDACTED:', $key);
    }

    expect($out['card_brand'])->toBe('visa')
        ->and($out['reference'])->toBe('BANK-2026-09-05')
        ->and($out['gateway_payment_ref'])->toBe('EXT-777')
        ->and($out['reason_code'])->toBe('bank_transfer')
        ->and($out['amount'])->toBe('49.90')
        ->and($out['currency'])->toBe('USD')
        ->and($out['company'])->toBe('Sanad') // "pan" inside "company" is not a segment
        ->and($out['span'])->toBe('keep')
        ->and($out['expand'])->toBe('keep');
});
