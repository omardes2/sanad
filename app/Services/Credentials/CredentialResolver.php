<?php

declare(strict_types=1);

namespace App\Services\Credentials;

use App\Data\Credentials\ResolvedCredential;
use App\Enums\CredentialSource;
use App\Enums\CredentialStatus;
use App\Models\AiProvider;
use App\Models\ProviderCredential;
use App\Services\Audit\AuditLogger;
use App\Services\Settings\SettingsRepository;
use App\Support\Audit\AuditActions;
use App\Support\Security\SecretString;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Decides which credential a provider runs with (Phase C3):
 *
 *  mode `env`   → the environment key (config/ai.php), vault ignored.
 *  mode `vault` → the provider's ACTIVE vault credential when one exists:
 *                 opened OK ⇒ used; missing master key / undecryptable /
 *                 provider mismatch ⇒ the provider is FAILED CLOSED (no
 *                 secret, `failure` set, warning + system audit once per
 *                 window) — never a silent fallback to env;
 *                 no active vault credential ⇒ the environment key (the
 *                 transition period), or none.
 *
 * Plaintext is decrypted per call and never cached.
 */
class CredentialResolver
{
    public const MODE_ENV = 'env';

    public const MODE_VAULT = 'vault';

    /** Seconds between repeated fail-closed audit entries for the same row. */
    public const FAILURE_AUDIT_WINDOW = 900;

    public function __construct(
        private readonly CredentialVault $vault,
        private readonly SettingsRepository $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function mode(): string
    {
        $mode = strtolower(trim((string) $this->settings->get('ai.credentials_mode')));

        return $mode === self::MODE_VAULT ? self::MODE_VAULT : self::MODE_ENV;
    }

    public function resolve(string $providerKey): ResolvedCredential
    {
        if ($this->mode() === self::MODE_VAULT) {
            $active = $this->activeCredential($providerKey);

            if ($active !== null) {
                return $this->fromVault($active, $providerKey);
            }
        }

        return $this->fromEnv($providerKey);
    }

    /**
     * The environment key as the runtime sees it (display: fingerprint only).
     */
    public function fromEnv(string $providerKey): ResolvedCredential
    {
        $raw = config("ai.providers.{$providerKey}.api_key");
        $raw = is_string($raw) ? trim($raw) : '';

        if ($raw === '') {
            return ResolvedCredential::none();
        }

        return new ResolvedCredential(CredentialSource::Env, new SecretString($raw), null, SecretString::fingerprintOf($raw));
    }

    public function envFingerprint(string $providerKey): ?string
    {
        $raw = config("ai.providers.{$providerKey}.api_key");

        return is_string($raw) && trim($raw) !== '' ? SecretString::fingerprintOf(trim($raw)) : null;
    }

    public function activeCredential(string $providerKey): ?ProviderCredential
    {
        try {
            if (! Schema::hasTable('provider_credentials')) {
                return null;
            }

            return ProviderCredential::query()
                ->whereIn('provider_id', AiProvider::query()->where('key', $providerKey)->select('id'))
                ->where('status', CredentialStatus::Active->value)
                ->first();
        } catch (Throwable $e) {
            Log::warning('sanad.credentials.lookup_failed', ['provider' => $providerKey, 'error' => $e::class]);

            return null;
        }
    }

    private function fromVault(ProviderCredential $credential, string $providerKey): ResolvedCredential
    {
        $outcome = $this->vault->open($credential, $providerKey);

        if ($outcome->isOk() && $outcome->secret !== null) {
            return new ResolvedCredential(CredentialSource::Vault, $outcome->secret, $credential->id, $credential->fingerprint, $credential->last4);
        }

        $failure = (string) $outcome->failure;
        Log::warning('sanad.credentials.failed_closed', ['provider' => $providerKey, 'credential_id' => $credential->id, 'failure' => $failure]);
        $this->auditFailureOnce($credential, $providerKey, $failure);

        return ResolvedCredential::closed($failure, $credential->id, $credential->fingerprint);
    }

    private function auditFailureOnce(ProviderCredential $credential, string $providerKey, string $failure): void
    {
        try {
            if (! Cache::add("sanad.credentials.failure_audited.{$credential->id}.{$failure}", 1, self::FAILURE_AUDIT_WINDOW)) {
                return;
            }

            $this->audit->record(AuditActions::AiCredentialResolveFailed, $credential, [], [
                'provider' => $providerKey,
                'failure' => $failure,
                'fingerprint' => $credential->fingerprint,
                'key_id' => $credential->key_id,
                'vault_key_id' => $this->vault->keyId(),
            ]);
        } catch (Throwable $e) {
            Log::warning('sanad.credentials.failure_audit_unavailable', ['error' => $e::class]);
        }
    }
}
