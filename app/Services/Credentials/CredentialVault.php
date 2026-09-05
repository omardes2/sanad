<?php

declare(strict_types=1);

namespace App\Services\Credentials;

use App\Data\Credentials\OpenOutcome;
use App\Data\Credentials\SealedSecret;
use App\Exceptions\Credentials\VaultUnavailableException;
use App\Models\ProviderCredential;
use App\Support\Security\SecretString;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use SensitiveParameter;
use Throwable;

/**
 * Seals and opens provider secrets (Phase C3) with a master key that is
 * INDEPENDENT of APP_KEY (config/credentials.php → CREDENTIALS_KEY), cipher
 * AES-256-GCM (authenticated: a tampered row cannot decrypt).
 *
 * Envelope (JSON in `ciphertext`): {"v":1,"kid":"<8 hex>","ct":"<payload>"}
 * where the payload encrypts {"p":"<provider key>","s":"<secret>"} — the
 * provider key inside the plaintext means a ciphertext row cannot be swapped
 * between providers undetected.
 *
 * Fail-closed by construction: without a valid key seal() throws and open()
 * reports `vault_unavailable`; a row sealed by an unknown key or altered in
 * any byte reports `undecryptable`. Previous keys (CREDENTIALS_PREVIOUS_KEYS)
 * open older rows during a master-key rotation.
 */
class CredentialVault
{
    public const VERSION = 1;

    private ?Encrypter $encrypter = null;

    private ?string $keyId = null;

    /** @var array<string, string> key id => raw key (current + previous) */
    private array $knownKeys = [];

    private bool $booted = false;

    /**
     * @param  array{key?: ?string, previous_keys?: ?string, cipher?: ?string}  $config
     */
    public function __construct(private readonly array $config) {}

    public function available(): bool
    {
        $this->boot();

        return $this->encrypter !== null;
    }

    /**
     * Id of the CURRENT master key (8 hex of its SHA-256), null when unavailable.
     */
    public function keyId(): ?string
    {
        $this->boot();

        return $this->keyId;
    }

    /**
     * @return list<string> ids of every key the vault can open (current first)
     */
    public function knownKeyIds(): array
    {
        $this->boot();

        return array_keys($this->knownKeys);
    }

    /**
     * @throws VaultUnavailableException
     */
    public function seal(string $providerKey, #[SensitiveParameter] SecretString $secret): SealedSecret
    {
        $this->boot();

        if ($this->encrypter === null || $this->keyId === null) {
            throw VaultUnavailableException::missingKey();
        }

        $payload = $this->encrypter->encrypt(json_encode(['p' => $providerKey, 's' => $secret->reveal()], JSON_THROW_ON_ERROR), false);

        return new SealedSecret(
            ciphertext: json_encode(['v' => self::VERSION, 'kid' => $this->keyId, 'ct' => $payload], JSON_THROW_ON_ERROR),
            keyId: $this->keyId,
            fingerprint: $secret->fingerprint(),
            last4: $secret->last4(),
        );
    }

    public function open(ProviderCredential $credential, string $expectedProviderKey): OpenOutcome
    {
        return $this->openCiphertext((string) $credential->getAttribute('ciphertext'), $expectedProviderKey);
    }

    public function openCiphertext(string $ciphertext, string $expectedProviderKey): OpenOutcome
    {
        $this->boot();

        if ($this->encrypter === null) {
            return OpenOutcome::failed(OpenOutcome::VAULT_UNAVAILABLE);
        }

        try {
            $envelope = json_decode($ciphertext, true, 8, JSON_THROW_ON_ERROR);

            if (! is_array($envelope) || ! isset($envelope['kid'], $envelope['ct']) || ! is_string($envelope['ct'])) {
                return OpenOutcome::failed(OpenOutcome::UNDECRYPTABLE);
            }

            $plain = $this->encrypter->decrypt($envelope['ct'], false);
            $data = json_decode((string) $plain, true, 4, JSON_THROW_ON_ERROR);
        } catch (DecryptException|Throwable) {
            return OpenOutcome::failed(OpenOutcome::UNDECRYPTABLE);
        }

        if (! is_array($data) || ! isset($data['p'], $data['s']) || ! is_string($data['s'])) {
            return OpenOutcome::failed(OpenOutcome::UNDECRYPTABLE);
        }

        if ($data['p'] !== $expectedProviderKey) {
            return OpenOutcome::failed(OpenOutcome::PROVIDER_MISMATCH);
        }

        return OpenOutcome::ok(new SecretString($data['s']));
    }

    /**
     * Which master key sealed a stored envelope (null when unreadable).
     */
    public static function keyIdOf(string $ciphertext): ?string
    {
        $envelope = json_decode($ciphertext, true);

        return is_array($envelope) && is_string($envelope['kid'] ?? null) ? $envelope['kid'] : null;
    }

    public static function idForKey(#[SensitiveParameter] string $rawKey): string
    {
        return substr(hash('sha256', $rawKey), 0, 8);
    }

    private function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;
        $cipher = strtolower((string) ($this->config['cipher'] ?? 'aes-256-gcm'));
        $current = self::decodeKey($this->config['key'] ?? null);

        if ($current === null || ! Encrypter::supported($current, $cipher)) {
            return;
        }

        $previous = [];

        foreach (array_filter(array_map('trim', explode(',', (string) ($this->config['previous_keys'] ?? '')))) as $candidate) {
            $decoded = self::decodeKey($candidate);

            if ($decoded !== null && Encrypter::supported($decoded, $cipher)) {
                $previous[] = $decoded;
            }
        }

        $this->encrypter = new Encrypter($current, $cipher);

        if ($previous !== []) {
            $this->encrypter->previousKeys($previous);
        }

        $this->keyId = self::idForKey($current);
        $this->knownKeys = [$this->keyId => $current];

        foreach ($previous as $raw) {
            $this->knownKeys[self::idForKey($raw)] = $raw;
        }
    }

    private static function decodeKey(#[SensitiveParameter] mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (str_starts_with($value, 'base64:')) {
            $decoded = base64_decode(substr($value, 7), true);

            return $decoded === false ? null : $decoded;
        }

        return $value;
    }
}
