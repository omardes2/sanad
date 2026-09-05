<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Credential vault (Phase C3)
|--------------------------------------------------------------------------
| The master key that encrypts provider credentials at rest. It is
| INDEPENDENT of APP_KEY: compromising sessions/cookies must not expose
| provider secrets, and rotating one must not force rotating the other.
|
| Captured here at config time (config:cache safe) and read only through
| config() — never env() at runtime. NULL/empty = the vault is unavailable:
| nothing can be sealed, and a provider whose active credential lives in the
| vault fails CLOSED (see CredentialResolver). The emergency rollback is
| AI_CREDENTIALS_MODE=env (config/ai.php).
|
| Rotation: put the new key in CREDENTIALS_KEY, the old one(s) in
| CREDENTIALS_PREVIOUS_KEYS, deploy, then run `sanad:credentials:rotate-key
| --apply` to re-encrypt every row with the new key. Keys are base64 of 32
| random bytes ("base64:..." like APP_KEY). Cipher: AES-256-GCM (AEAD).
*/
return [
    'key' => env('CREDENTIALS_KEY'),
    'previous_keys' => env('CREDENTIALS_PREVIOUS_KEYS', ''),
    'cipher' => 'aes-256-gcm',
];
