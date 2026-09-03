<?php

declare(strict_types=1);

use App\Enums\MessageDeliveryStatus;
use App\Support\WhatsApp\WhatsAppPhone;
use App\Support\WhatsApp\WhatsAppSignature;

// ---- MessageDeliveryStatus monotonic transitions ------------------------

it('advances delivery status only forward along the happy path', function () {
    expect(MessageDeliveryStatus::Sent->isForwardFrom(MessageDeliveryStatus::Accepted))->toBeTrue()
        ->and(MessageDeliveryStatus::Delivered->isForwardFrom(MessageDeliveryStatus::Sent))->toBeTrue()
        ->and(MessageDeliveryStatus::Read->isForwardFrom(MessageDeliveryStatus::Delivered))->toBeTrue();
});

it('never moves delivery status backwards', function () {
    expect(MessageDeliveryStatus::Sent->isForwardFrom(MessageDeliveryStatus::Read))->toBeFalse()
        ->and(MessageDeliveryStatus::Delivered->isForwardFrom(MessageDeliveryStatus::Read))->toBeFalse()
        ->and(MessageDeliveryStatus::Accepted->isForwardFrom(MessageDeliveryStatus::Sent))->toBeFalse();
});

it('treats an identical delivery status as a no-op', function () {
    expect(MessageDeliveryStatus::Delivered->isForwardFrom(MessageDeliveryStatus::Delivered))->toBeFalse();
});

it('does not let failed override delivered or read', function () {
    expect(MessageDeliveryStatus::Failed->isForwardFrom(MessageDeliveryStatus::Delivered))->toBeFalse()
        ->and(MessageDeliveryStatus::Failed->isForwardFrom(MessageDeliveryStatus::Read))->toBeFalse()
        ->and(MessageDeliveryStatus::Failed->isForwardFrom(MessageDeliveryStatus::Sent))->toBeTrue()
        ->and(MessageDeliveryStatus::Failed->isForwardFrom(MessageDeliveryStatus::Accepted))->toBeTrue();
});

// ---- Signature ----------------------------------------------------------

it('validates a correct HMAC-SHA256 signature over the raw body', function () {
    $raw = '{"a":1}';
    $sig = 'sha256='.hash_hmac('sha256', $raw, 'secret');

    expect(WhatsAppSignature::isValid($raw, $sig, 'secret'))->toBeTrue();
});

it('rejects a wrong, missing, or malformed signature', function () {
    $raw = '{"a":1}';

    expect(WhatsAppSignature::isValid($raw, 'sha256='.hash_hmac('sha256', $raw, 'other'), 'secret'))->toBeFalse()
        ->and(WhatsAppSignature::isValid($raw, null, 'secret'))->toBeFalse()
        ->and(WhatsAppSignature::isValid($raw, 'sha256=nothex', 'secret'))->toBeFalse()
        ->and(WhatsAppSignature::isValid($raw, hash_hmac('sha256', $raw, 'secret'), 'secret'))->toBeFalse(); // missing prefix
});

// ---- Phone normalization ------------------------------------------------

it('normalizes bare digits to E.164', function () {
    expect(WhatsAppPhone::toE164('970599000001'))->toBe('+970599000001')
        ->and(WhatsAppPhone::toE164('1 555 000 1234'))->toBe('+15550001234');
});

it('rejects invalid phone numbers', function () {
    expect(WhatsAppPhone::toE164('abc'))->toBeNull()
        ->and(WhatsAppPhone::toE164('012345'))->toBeNull()   // leading zero
        ->and(WhatsAppPhone::toE164('123'))->toBeNull();     // too short
});

it('redacts phone numbers to the last 4 digits', function () {
    expect(WhatsAppPhone::redact('+970599000001'))->toBe('***0001')
        ->and(WhatsAppPhone::redact(null))->toBe('(none)');
});
