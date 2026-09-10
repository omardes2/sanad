<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Durable personal memory (Phase G)
|--------------------------------------------------------------------------
| Durable memory is NOT conversation history. History is `messages`, bounded
| to the last few turns of one conversation; a memory is a small, curated
| fact with future value that travels with the subscriber across every
| conversation. Only an EXPLICIT subscriber instruction creates one.
|
| KEYS ARE INDEPENDENT OF APP_KEY, and of each other, for the same reason the
| credential vault's is (config/credentials.php): compromising sessions or
| cookies must not expose a subscriber's memories, and rotating one key must
| not force rotating the others. NULL/empty `key` = memory is UNAVAILABLE:
| nothing is written, nothing is read, and nothing reaches a prompt. That is
| the correct failure — plaintext memory is not an acceptable fallback.
|
| Keys are base64 of 32 random bytes ("base64:..." like APP_KEY):
|   php artisan sanad:credentials:generate-key   (same shape, different key)
*/
return [
    /*
     | AES-256-GCM master key for `memories.content` at rest, and the previous
     | key(s) that older rows may still be sealed with during a rotation. There
     | is no re-encryption pass in this phase: a previous key must be retained
     | for as long as any row is still sealed with it.
     */
    'key' => env('MEMORY_KEY'),
    'previous_keys' => env('MEMORY_PREVIOUS_KEYS', ''),
    'cipher' => 'aes-256-gcm',

    /*
     | The MAC key behind the duplicate fingerprint. It is separate from the
     | encryption key on purpose: the fingerprint is an index, the ciphertext
     | is the secret, and neither should be derivable from the other. A raw
     | digest of normalised plaintext would let anyone holding the database
     | confirm a guessed memory ("does this subscriber remember X?") without
     | the encryption key at all — a keyed MAC removes that.
     */
    'fingerprint_key' => env('MEMORY_FINGERPRINT_KEY'),

    /*
     | Bounded storage. At capacity Sanad REFUSES a new memory; it never evicts
     | an explicit one the subscriber never asked to forget.
     */
    'max_active' => (int) env('MEMORY_MAX_ACTIVE', 50),
    'max_content_chars' => (int) env('MEMORY_MAX_CONTENT_CHARS', 300),

    /*
     | Prompt injection budget. Both bounds apply; whichever binds first wins.
     */
    'prompt_limit' => (int) env('MEMORY_PROMPT_LIMIT', 12),
    'prompt_max_chars' => (int) env('MEMORY_PROMPT_MAX_CHARS', 1200),
];
