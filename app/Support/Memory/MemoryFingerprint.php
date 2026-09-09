<?php

declare(strict_types=1);

namespace App\Support\Memory;

use App\Exceptions\Memory\MemoryUnavailableException;
use SensitiveParameter;

/**
 * The duplicate-detection fingerprint of a memory (Phase G): a KEYED MAC over
 * the normalised plaintext, never a bare digest of it.
 *
 * Why keyed. The fingerprint sits in an indexed column next to encrypted
 * content. A bare `sha256(normalised)` would be an offline confirmation
 * oracle: anyone holding a copy of the database — a backup, a replica, a
 * console query — could guess a sentence, hash it, and learn whether this
 * subscriber remembers it, WITHOUT the encryption key. Memories are short,
 * formulaic and drawn from a small space («بحب القهوة سادة»), so guessing is
 * cheap and the oracle is real. HMAC-SHA256 under a key that never leaves the
 * environment removes it: the digest is meaningless to anyone without the key.
 *
 * The key is INDEPENDENT of the content key (config/memory.php): the
 * fingerprint is an index and the ciphertext is the secret, and neither should
 * be derivable from the other. Without a key nothing can be fingerprinted, so
 * nothing can be written — memory fails closed exactly as the vault does.
 */
final class MemoryFingerprint
{
    /** Hex length of HMAC-SHA256, and therefore the column width. */
    public const LENGTH = 64;

    /**
     * Domain separation: this key is only ever used for this one purpose, so a
     * value computed here can never be replayed as a MAC of anything else.
     */
    private const CONTEXT = 'sanad:memory:fingerprint:v1';

    /**
     * @throws MemoryUnavailableException when no fingerprint key is configured
     */
    public static function of(#[SensitiveParameter] string $content): string
    {
        return hash_hmac('sha256', self::CONTEXT."\n".MemoryText::normalize($content), self::key());
    }

    public static function available(): bool
    {
        return self::rawKey() !== null;
    }

    private static function key(): string
    {
        return self::rawKey() ?? throw MemoryUnavailableException::missingFingerprintKey();
    }

    private static function rawKey(): ?string
    {
        $configured = config('memory.fingerprint_key');

        if (! is_string($configured) || trim($configured) === '') {
            return null;
        }

        $configured = trim($configured);

        // Accepts the same "base64:..." shape as APP_KEY and CREDENTIALS_KEY,
        // and a raw string for an operator who set one by hand.
        if (str_starts_with($configured, 'base64:')) {
            $decoded = base64_decode(substr($configured, 7), true);

            return $decoded === false || $decoded === '' ? null : $decoded;
        }

        return $configured;
    }
}
