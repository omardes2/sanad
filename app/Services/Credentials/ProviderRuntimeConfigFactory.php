<?php

declare(strict_types=1);

namespace App\Services\Credentials;

use App\Support\Security\SecretString;

/**
 * Builds the config array an adapter runs with (Phase C3): everything from
 * config/ai.php for the provider, except `api_key`, which is REPLACED by the
 * credential the resolver chose (a SecretString, or null when there is none
 * or the provider failed closed). The runtime base_url stays the config one —
 * the database base_url is stored-only in C3 and can only be exercised by a
 * Test Connection as a candidate.
 */
class ProviderRuntimeConfigFactory
{
    public function __construct(private readonly CredentialResolver $resolver) {}

    /**
     * @return array<string, mixed>
     */
    public function for(string $providerKey): array
    {
        /** @var array<string, mixed> $config */
        $config = (array) config("ai.providers.{$providerKey}", []);
        $resolved = $this->resolver->resolve($providerKey);

        $config['api_key'] = $resolved->usable() ? $resolved->secret : null;
        $config['credential_source'] = $resolved->source->value;
        $config['credential_id'] = $resolved->credentialId;
        $config['credential_failure'] = $resolved->failure;

        return $config;
    }

    /**
     * The same, but running with an EXPLICIT secret (a pending credential
     * under test) and/or a candidate base URL — used by Test Connection only.
     *
     * @param  array<string, mixed>  $httpOptions
     * @return array<string, mixed>
     */
    public function with(string $providerKey, ?SecretString $secret, ?string $baseUrl = null, array $httpOptions = []): array
    {
        /** @var array<string, mixed> $config */
        $config = (array) config("ai.providers.{$providerKey}", []);
        $config['api_key'] = $secret;
        $config['credential_source'] = $secret === null ? 'none' : 'explicit';
        $config['credential_id'] = null;
        $config['credential_failure'] = null;

        if ($baseUrl !== null) {
            $config['base_url'] = $baseUrl;
        }

        if ($httpOptions !== []) {
            $config['http_options'] = $httpOptions;
        }

        return $config;
    }
}
