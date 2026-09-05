<?php

declare(strict_types=1);

namespace App\Data\Credentials;

/**
 * What the vault stores for a secret: the ciphertext and the id of the
 * master key that sealed it. No plaintext.
 */
final readonly class SealedSecret
{
    public function __construct(
        public string $ciphertext,
        public string $keyId,
        public string $fingerprint,
        public string $last4,
    ) {}
}
