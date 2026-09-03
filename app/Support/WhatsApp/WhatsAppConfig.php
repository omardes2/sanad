<?php

declare(strict_types=1);

namespace App\Support\WhatsApp;

use App\Exceptions\WhatsAppConfigurationException;

/**
 * Typed, safe view over config/whatsapp.php.
 *
 * Secrets (access token, app secret, verify token) are private; the getters
 * throw WhatsAppConfigurationException when a required secret is missing so
 * the integration fails closed rather than sending/verifying with a blank
 * credential. No getter ever exposes a secret through an exception message.
 */
final class WhatsAppConfig
{
    public function __construct(
        public readonly bool $enabled,
        public readonly string $graphBaseUrl,
        public readonly string $graphVersion,
        private readonly ?string $accessToken,
        private readonly ?string $appSecret,
        private readonly ?string $verifyToken,
        public readonly ?string $phoneNumberId,
        public readonly ?string $businessAccountId,
        public readonly int $requestTimeout,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            enabled: (bool) config('whatsapp.enabled', false),
            graphBaseUrl: rtrim((string) config('whatsapp.graph_base_url'), '/'),
            graphVersion: trim((string) config('whatsapp.graph_version'), '/'),
            accessToken: self::nullableString(config('whatsapp.access_token')),
            appSecret: self::nullableString(config('whatsapp.app_secret')),
            verifyToken: self::nullableString(config('whatsapp.verify_token')),
            phoneNumberId: self::nullableString(config('whatsapp.phone_number_id')),
            businessAccountId: self::nullableString(config('whatsapp.business_account_id')),
            requestTimeout: (int) config('whatsapp.request_timeout', 10),
        );
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * True when everything needed to SEND a message is present.
     */
    public function canSend(): bool
    {
        return $this->enabled
            && $this->accessToken !== null
            && $this->phoneNumberId !== null
            && $this->graphVersion !== '';
    }

    /**
     * True when the GET verification handshake can be evaluated.
     */
    public function canVerifyWebhook(): bool
    {
        return $this->verifyToken !== null;
    }

    /**
     * True when POST signatures can be validated.
     */
    public function canValidateSignature(): bool
    {
        return $this->appSecret !== null;
    }

    public function accessToken(): string
    {
        return $this->accessToken ?? throw WhatsAppConfigurationException::missing('access_token');
    }

    public function appSecret(): string
    {
        return $this->appSecret ?? throw WhatsAppConfigurationException::missing('app_secret');
    }

    public function verifyToken(): string
    {
        return $this->verifyToken ?? throw WhatsAppConfigurationException::missing('verify_token');
    }

    public function phoneNumberId(): string
    {
        return $this->phoneNumberId ?? throw WhatsAppConfigurationException::missing('phone_number_id');
    }

    /**
     * Assert the integration is fully configured for sending; fail closed.
     */
    public function assertCanSend(): void
    {
        if (! $this->enabled) {
            throw WhatsAppConfigurationException::disabled();
        }
        if ($this->accessToken === null) {
            throw WhatsAppConfigurationException::missing('access_token');
        }
        if ($this->phoneNumberId === null) {
            throw WhatsAppConfigurationException::missing('phone_number_id');
        }
        if ($this->graphVersion === '') {
            throw WhatsAppConfigurationException::missing('graph_version');
        }
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
