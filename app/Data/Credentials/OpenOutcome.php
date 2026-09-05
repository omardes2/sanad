<?php

declare(strict_types=1);

namespace App\Data\Credentials;

use App\Support\Security\SecretString;

/**
 * Result of opening a sealed credential. `failure` is null on success and one
 * of vault_unavailable / undecryptable / provider_mismatch otherwise — safe to
 * audit and display.
 */
final readonly class OpenOutcome
{
    public const VAULT_UNAVAILABLE = 'vault_unavailable';

    public const UNDECRYPTABLE = 'undecryptable';

    public const PROVIDER_MISMATCH = 'provider_mismatch';

    private function __construct(
        public ?SecretString $secret,
        public ?string $failure,
    ) {}

    public static function ok(SecretString $secret): self
    {
        return new self($secret, null);
    }

    public static function failed(string $failure): self
    {
        return new self(null, $failure);
    }

    public function isOk(): bool
    {
        return $this->failure === null && $this->secret !== null;
    }
}
