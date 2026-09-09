<?php

declare(strict_types=1);

namespace App\Services\Memory;

use App\Exceptions\Memory\MemoryUnavailableException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use SensitiveParameter;
use Throwable;

/**
 * Seals and opens `memories.content` at rest (Phase G), with a master key that
 * is INDEPENDENT of APP_KEY and of the credential vault's — the same reasoning
 * as `CredentialVault`, applied to the most personal data Sanad holds.
 *
 * Cipher AES-256-GCM (authenticated): a row altered in any byte does not
 * decrypt, it fails. Envelope, stored as JSON in `content`:
 *
 *     {"v":1,"kid":"<8 hex>","ct":"<payload>"}
 *
 * The key id lets a rotation be observed and lets an old row be opened by a
 * previous key (MEMORY_PREVIOUS_KEYS) while the new one seals everything new.
 *
 * FAIL-CLOSED, with no plaintext path anywhere: without a valid key `seal()`
 * throws and `open()` returns null. A caller that cannot open a row treats it
 * as unreadable — it is skipped, never guessed at, and never rendered.
 *
 * BOUNDED DECRYPTION. Nothing here decrypts more than the caller hands it, and
 * every caller in the platform works from ONE subscriber's bounded active set.
 * There is no path that decrypts across subscribers, and no query that scans
 * ciphertext — searching happens in application memory over that bounded set.
 */
class MemoryCipher
{
    public const VERSION = 1;

    private ?Encrypter $encrypter = null;

    private ?string $keyId = null;

    /** @var array<string, Encrypter> key id => opener (current + previous) */
    private array $openers = [];

    private bool $booted = false;

    public function available(): bool
    {
        $this->boot();

        return $this->encrypter !== null;
    }

    /** Id of the CURRENT key (8 hex of its SHA-256), null when unavailable. */
    public function keyId(): ?string
    {
        $this->boot();

        return $this->keyId;
    }

    /**
     * @throws MemoryUnavailableException
     */
    public function seal(#[SensitiveParameter] string $plaintext): string
    {
        $this->boot();

        if ($this->encrypter === null || $this->keyId === null) {
            throw MemoryUnavailableException::missingKey();
        }

        return (string) json_encode([
            'v' => self::VERSION,
            'kid' => $this->keyId,
            'ct' => $this->encrypter->encrypt($plaintext, false),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * The plaintext of one sealed value, or NULL when it cannot be opened —
     * no key, an unknown key id, or a tampered/corrupt envelope. A caller that
     * gets null must treat the row as unreadable and skip it: there is no
     * plaintext fallback and a legacy plaintext row is not silently accepted,
     * because accepting it would mean a row written before this phase could
     * still be read as if it had been protected all along.
     */
    public function open(string $sealed): ?string
    {
        $this->boot();

        if ($this->encrypter === null) {
            return null;
        }

        try {
            $envelope = json_decode($sealed, true, 8, JSON_THROW_ON_ERROR);

            if (! is_array($envelope) || ! isset($envelope['kid'], $envelope['ct'])
                || ! is_string($envelope['kid']) || ! is_string($envelope['ct'])) {
                return null;
            }

            $opener = $this->openers[$envelope['kid']] ?? null;

            if ($opener === null) {
                return null;
            }

            $plain = $opener->decrypt($envelope['ct'], false);
        } catch (DecryptException|Throwable) {
            return null;
        }

        return is_string($plain) ? $plain : null;
    }

    // ------------------------------------------------------------ internals

    private function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        $cipher = (string) config('memory.cipher', 'aes-256-gcm');
        $current = self::decodeKey(config('memory.key'));

        if ($current === null || ! Encrypter::supported($current, $cipher)) {
            return;     // unavailable: seal() throws, open() returns null
        }

        $this->encrypter = new Encrypter($current, $cipher);
        $this->keyId = self::idOf($current);
        $this->openers = [$this->keyId => $this->encrypter];

        foreach (explode(',', (string) config('memory.previous_keys', '')) as $previous) {
            $decoded = self::decodeKey($previous);

            if ($decoded !== null && Encrypter::supported($decoded, $cipher)) {
                $this->openers[self::idOf($decoded)] ??= new Encrypter($decoded, $cipher);
            }
        }
    }

    /** Forget the cached key material — tests that change configuration only. */
    public function flush(): void
    {
        $this->booted = false;
        $this->encrypter = null;
        $this->keyId = null;
        $this->openers = [];
    }

    private static function decodeKey(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (str_starts_with($value, 'base64:')) {
            $decoded = base64_decode(substr($value, 7), true);

            return $decoded === false || $decoded === '' ? null : $decoded;
        }

        return $value;
    }

    private static function idOf(#[SensitiveParameter] string $key): string
    {
        return substr(hash('sha256', $key), 0, 8);
    }
}
