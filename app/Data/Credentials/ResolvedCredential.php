<?php

declare(strict_types=1);

namespace App\Data\Credentials;

use App\Enums\CredentialSource;
use App\Support\Security\SecretString;

/**
 * What the runtime got for a provider (Phase C3): the secret (or null), where
 * it came from, and — when the provider is FAILED CLOSED — why. A closed
 * provider has source none, no secret and a non-null failure; it is skipped by
 * the router with reason `credential_failed` and never falls back silently.
 */
final readonly class ResolvedCredential
{
    public function __construct(
        public CredentialSource $source,
        public ?SecretString $secret,
        public ?int $credentialId = null,
        public ?string $fingerprint = null,
        public ?string $last4 = null,
        public ?string $failure = null,
    ) {}

    public static function none(): self
    {
        return new self(CredentialSource::None, null);
    }

    public static function closed(string $failure, ?int $credentialId, ?string $fingerprint): self
    {
        return new self(CredentialSource::None, null, $credentialId, $fingerprint, null, $failure);
    }

    public function usable(): bool
    {
        return $this->secret !== null && ! $this->secret->isEmpty() && $this->failure === null;
    }

    public function failedClosed(): bool
    {
        return $this->failure !== null;
    }
}
