<?php

declare(strict_types=1);

use App\Data\Credentials\OpenOutcome;
use App\Exceptions\Credentials\VaultUnavailableException;
use App\Services\Credentials\CredentialVault;
use App\Support\Security\SecretString;
use Illuminate\Encryption\Encrypter;

it('is unavailable without a valid CREDENTIALS_KEY: seal refuses, open reports vault_unavailable', function () {
    c3VaultOff();
    $vault = app(CredentialVault::class);

    expect($vault->available())->toBeFalse()
        ->and($vault->keyId())->toBeNull()
        ->and(fn () => $vault->seal('groq', new SecretString('gsk_x')))->toThrow(VaultUnavailableException::class)
        ->and($vault->openCiphertext('{"v":1,"kid":"deadbeef","ct":"x"}', 'groq')->failure)->toBe(OpenOutcome::VAULT_UNAVAILABLE);

    // A key of the wrong length is not a key.
    config(['credentials.key' => 'base64:'.base64_encode(random_bytes(16))]);
    expect(app(CredentialVault::class)->available())->toBeFalse();
});

it('uses AES-256-GCM through the framework Encrypter, seals and opens a round trip, and never stores the plaintext', function () {
    c3VaultOn();
    $vault = app(CredentialVault::class);
    $secret = new SecretString('gsk_ROUNDTRIP_SECRET_9876');

    $sealed = $vault->seal('groq', $secret);

    expect(config('credentials.cipher'))->toBe('aes-256-gcm')
        ->and(Encrypter::supported(random_bytes(32), 'aes-256-gcm'))->toBeTrue()
        ->and($vault->available())->toBeTrue()
        ->and($vault->keyId())->toMatch('/^[0-9a-f]{8}$/')
        ->and($sealed->keyId)->toBe($vault->keyId())
        ->and($sealed->fingerprint)->toBe($secret->fingerprint())
        ->and($sealed->last4)->toBe('9876')
        ->and($sealed->ciphertext)->not->toContain('ROUNDTRIP')
        ->and($sealed->ciphertext)->not->toContain(base64_encode('gsk_ROUNDTRIP_SECRET_9876'))
        ->and(CredentialVault::keyIdOf($sealed->ciphertext))->toBe($vault->keyId());

    $outcome = $vault->openCiphertext($sealed->ciphertext, 'groq');

    expect($outcome->isOk())->toBeTrue()
        ->and($outcome->secret?->reveal())->toBe('gsk_ROUNDTRIP_SECRET_9876');
});

it('reports undecryptable for a tampered, foreign-key or malformed ciphertext and provider_mismatch for a swapped row', function () {
    $keyA = c3VaultOn();
    $sealed = app(CredentialVault::class)->seal('groq', new SecretString('gsk_TAMPER_1111'));

    // Flip one character inside the payload → GCM authentication fails.
    $envelope = json_decode($sealed->ciphertext, true);
    $ct = $envelope['ct'];
    $envelope['ct'] = substr($ct, 0, 40).(($ct[40] ?? 'a') === 'a' ? 'b' : 'a').substr($ct, 41);
    $tampered = json_encode($envelope);

    $vault = app(CredentialVault::class);

    expect($vault->openCiphertext($tampered, 'groq')->failure)->toBe(OpenOutcome::UNDECRYPTABLE)
        ->and($vault->openCiphertext('not json', 'groq')->failure)->toBe(OpenOutcome::UNDECRYPTABLE)
        ->and($vault->openCiphertext('{"v":1}', 'groq')->failure)->toBe(OpenOutcome::UNDECRYPTABLE)
        ->and($vault->openCiphertext($sealed->ciphertext, 'openai')->failure)->toBe(OpenOutcome::PROVIDER_MISMATCH);

    // A different master key with no previous keys cannot open it.
    c3VaultOn(c3Key());
    expect(app(CredentialVault::class)->openCiphertext($sealed->ciphertext, 'groq')->failure)->toBe(OpenOutcome::UNDECRYPTABLE);

    // With the old key listed as previous, it opens again — and re-sealing uses the NEW key id.
    $keyB = c3VaultOn(c3Key(), $keyA);
    $vault = app(CredentialVault::class);
    $opened = $vault->openCiphertext($sealed->ciphertext, 'groq');

    expect($opened->isOk())->toBeTrue()
        ->and($vault->knownKeyIds())->toHaveCount(2)
        ->and($vault->seal('groq', $opened->secret)->keyId)->toBe(CredentialVault::idForKey(base64_decode(substr($keyB, 7))))
        ->and($vault->seal('groq', $opened->secret)->keyId)->not->toBe($sealed->keyId);
});
