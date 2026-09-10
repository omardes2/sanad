<?php

declare(strict_types=1);

namespace App\Support\Memory;

use App\Enums\MemoryCategory;
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
 *
 * SCOPED TO THE OWNER, not just to the text. A MAC over the content alone would
 * be equal for two different subscribers who happen to remember the same thing.
 * The plaintext would still be out of reach, but an attacker holding the database
 * would learn something they have no business learning: «subscriber A and
 * subscriber B share a hidden memory». That linkage is unnecessary for the one
 * job this value has — recognising ONE subscriber's duplicate inside ONE category
 * — so the owner and the category are part of what is MAC'd, and the uniqueness
 * constraint `(user_id, category, fingerprint)` stays exactly as it was.
 *
 * THE ENCODING IS UNAMBIGUOUS. Every part is length-prefixed, so no combination
 * of ids, category names and content can be re-split into a different triple —
 * a plain `a || b || c` concatenation could. A versioned domain tag leads the
 * input so the construction can change later without any old value colliding
 * with a new one.
 *
 * The subscriber identity comes from TRUSTED DOMAIN CONTEXT — the owner of the
 * stored message — and never from a payload: no tool schema declares an owner id,
 * and no provider-visible identifier is used here.
 */
final class MemoryFingerprint
{
    /** Hex length of HMAC-SHA256, and therefore the column width. */
    public const LENGTH = 64;

    /**
     * Domain separation: this key is only ever used for this one purpose and this
     * one construction, so a value computed here can never be replayed as a MAC
     * of anything else, and a future construction can bump the version.
     */
    public const DOMAIN = 'sanad-memory-fingerprint-v1';

    /**
     * The duplicate identity of ONE memory, for ONE subscriber, in ONE category.
     *
     * @param  int  $subscriberId  from trusted context — never a payload field
     *
     * @throws MemoryUnavailableException when no fingerprint key is configured
     */
    public static function of(int $subscriberId, MemoryCategory|string $category, #[SensitiveParameter] string $content): string
    {
        $category = $category instanceof MemoryCategory ? $category->value : $category;

        return hash_hmac('sha256', self::encode([
            self::DOMAIN,
            (string) $subscriberId,
            $category,
            MemoryText::normalize($content),
        ]), self::key());
    }

    /**
     * Length-prefixed, so the parts can never be re-split into a different
     * triple: `<byte length>:<bytes>` for each, joined by a separator that is
     * itself unnecessary for correctness and present only for readability in a
     * failing test.
     *
     * @param  list<string>  $parts
     */
    private static function encode(array $parts): string
    {
        return implode('|', array_map(static fn (string $part): string => strlen($part).':'.$part, $parts));
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
