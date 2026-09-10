<?php

declare(strict_types=1);

use App\Enums\MemoryCategory;
use App\Exceptions\Memory\MemoryUnavailableException;
use App\Models\Memory;
use App\Models\User;
use App\Services\Memory\MemoryCipher;
use App\Services\Memory\MemoryService;
use App\Support\Memory\MemoryFingerprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Phase G — the encryption envelope contract for `memories.content`, stated as
 * tests so it cannot drift.
 *
 * AES-256-GCM under a key INDEPENDENT of APP_KEY and of the credential vault's.
 * Authenticated: a row altered in any byte does not decrypt, it fails. There is
 * no plaintext path anywhere — not for a missing key, not for a wrong key, not
 * for a corrupt row.
 */
function cipher(): MemoryCipher
{
    return app(MemoryCipher::class);
}

/** A fresh cipher that reads the current configuration. */
function reboot(): MemoryCipher
{
    cipher()->flush();

    return cipher();
}

function aKey(string $seed): string
{
    return 'base64:'.base64_encode(hash('sha256', $seed, true));
}

it('seals with AES-256-GCM under a dedicated key, and stamps the key id on every row', function () {
    expect(config('memory.cipher'))->toBe('aes-256-gcm')
        // The three secrets are distinct, and none of them is APP_KEY.
        ->and(config('memory.key'))->not->toBe(config('app.key'))
        ->and(config('memory.key'))->not->toBe(config('memory.fingerprint_key'))
        ->and(config('memory.key'))->not->toBe(config('credentials.key'));

    $sealed = cipher()->seal('بحب القهوة سادة');
    $envelope = json_decode($sealed, true);

    expect($envelope['v'])->toBe(MemoryCipher::VERSION)
        ->and($envelope['kid'])->toBe(cipher()->keyId())
        ->and($envelope['kid'])->toHaveLength(8)
        // The envelope carries no readable trace of the memory.
        ->and($sealed)->not->toContain('القهوة')
        ->and(cipher()->open($sealed))->toBe('بحب القهوة سادة');

    // Two seals of the same text differ (fresh IV), so the ciphertext itself is
    // not a duplicate oracle — that job belongs to the keyed fingerprint.
    expect(cipher()->seal('بحب القهوة سادة'))->not->toBe($sealed);
});

it('fails closed on every malformed or tampered envelope, and never guesses', function () {
    $sealed = cipher()->seal('سرّ المشترك');
    $envelope = json_decode($sealed, true);

    $broken = [
        'not json at all',
        '',
        '{}',
        // Legacy plaintext is NOT silently accepted: a row written before this
        // phase must not be readable as if it had been protected all along.
        'بحب القهوة سادة',
        // A valid envelope whose key id is unknown.
        (string) json_encode(['v' => 1, 'kid' => 'deadbeef', 'ct' => $envelope['ct']]),
        // The AEAD payload altered by one character: the tag no longer verifies.
        (string) json_encode(['v' => 1, 'kid' => $envelope['kid'], 'ct' => substr($envelope['ct'], 0, -4).'AAAA']),
        // Truncated payload.
        (string) json_encode(['v' => 1, 'kid' => $envelope['kid'], 'ct' => substr($envelope['ct'], 0, 20)]),
        // Structurally wrong envelope.
        (string) json_encode(['v' => 1, 'ct' => $envelope['ct']]),
        (string) json_encode(['v' => 1, 'kid' => $envelope['kid']]),
    ];

    foreach ($broken as $value) {
        expect(cipher()->open($value))->toBeNull(substr($value, 0, 40));
    }

    // The intact one still opens, so the refusals above are not a broken cipher.
    expect(cipher()->open($sealed))->toBe('سرّ المشترك');
});

it('is unavailable, not plaintext, when no key is configured', function () {
    config(['memory.key' => null]);
    $cipher = reboot();

    expect($cipher->available())->toBeFalse()
        ->and($cipher->keyId())->toBeNull()
        ->and($cipher->open('anything'))->toBeNull()
        ->and(fn () => $cipher->seal('بحب القهوة'))->toThrow(MemoryUnavailableException::class);

    // A key of the wrong length for the cipher is the same as no key at all.
    config(['memory.key' => 'base64:'.base64_encode('too-short')]);
    expect(reboot()->available())->toBeFalse();

    // The fingerprint key is independently required, and equally fail-closed.
    config(['memory.key' => aKey('ok'), 'memory.fingerprint_key' => null]);
    expect(reboot()->available())->toBeTrue()
        ->and(MemoryFingerprint::available())->toBeFalse()
        ->and(fn () => MemoryFingerprint::of(1, MemoryCategory::Fact, 'x'))
        ->toThrow(MemoryUnavailableException::class);
});

it('opens rows sealed by a previous key during a rotation, and seals new ones with the current one', function () {
    $old = aKey('rotation-old');
    $new = aKey('rotation-new');

    // A memory written before the rotation.
    config(['memory.key' => $old, 'memory.previous_keys' => '']);
    $before = reboot()->seal('بحب القهوة سادة');
    $oldKeyId = reboot()->keyId();

    // Rotate: the new key seals, the old one still opens.
    config(['memory.key' => $new, 'memory.previous_keys' => $old]);
    $cipher = reboot();

    expect($cipher->keyId())->not->toBe($oldKeyId)
        ->and($cipher->open($before))->toBe('بحب القهوة سادة')
        ->and(json_decode($cipher->seal('جديد'), true)['kid'])->toBe($cipher->keyId());

    // WITHOUT the previous key the old row is simply unreadable. There is no
    // re-encryption pass in this phase, so the previous key must be RETAINED for
    // as long as any row is still sealed with it — losing it loses those
    // memories, and that is the documented cost of encrypting them.
    config(['memory.previous_keys' => '']);
    expect(reboot()->open($before))->toBeNull();
});

it('treats an unreadable row as unreadable everywhere, and never fails the whole request over one', function () {
    $user = User::factory()->create();
    Memory::factory()->create(['user_id' => $user->id, 'content' => 'بحب القهوة سادة', 'category' => MemoryCategory::Preference->value]);
    Memory::factory()->create(['user_id' => $user->id, 'content' => 'بحب الشاي مساءً', 'category' => MemoryCategory::Preference->value]);

    // Corrupt exactly one row, as a bad backup restore or a partial rotation would.
    DB::table('memories')->orderBy('id')->limit(1)->update(['content' => 'garbage-not-an-envelope']);

    $service = app(MemoryService::class);

    // The readable one still answers; the corrupt one is skipped, not guessed at
    // and not fatal — one bad row must not take down every AI reply.
    expect($service->recall($user, ['query' => 'الشاي'])['memories'])->toHaveCount(1)
        ->and($service->recall($user, ['query' => 'القهوة'])['memories'])->toBe([])
        ->and($service->forPrompt($user, 12))->toHaveCount(1);
});

it('keeps the two secrets out of the repository', function () {
    // The suite runs on fixed TEST keys declared in phpunit.xml; nothing in the
    // tracked application config or environment example carries a real one.
    foreach ([base_path('config/memory.php'), base_path('.env.example')] as $file) {
        $contents = (string) file_get_contents($file);

        expect($contents)->not->toContain((string) config('memory.key'))
            ->and($contents)->not->toContain((string) config('memory.fingerprint_key'))
            // Both are read from the environment, never literals.
            ->and($contents)->toContain('MEMORY_KEY');
    }

    expect(Encrypter::supported((string) base64_decode(substr((string) config('memory.key'), 7), true), 'aes-256-gcm'))->toBeTrue();
});
