<?php

declare(strict_types=1);

namespace App\Contracts\Ai;

/**
 * An adapter that can say WHY it is not configured (Phase C3): a provider
 * whose active vault credential could not be opened is FAILED CLOSED, and the
 * router reports `credential_failed` instead of a plain `unconfigured`.
 */
interface ReportsCredentialState
{
    /**
     * vault_unavailable | undecryptable | provider_mismatch, or null.
     */
    public function credentialFailure(): ?string;

    /**
     * vault | env | explicit | none
     */
    public function credentialSource(): string;
}
