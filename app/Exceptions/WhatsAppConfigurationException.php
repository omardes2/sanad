<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the WhatsApp integration is enabled but required configuration
 * is missing, or when a caller requests a secret that is not set. The message
 * names only which setting is missing — never the value of any secret.
 */
class WhatsAppConfigurationException extends RuntimeException
{
    public static function missing(string $setting): self
    {
        return new self("WhatsApp integration is misconfigured: missing [{$setting}].");
    }

    public static function disabled(): self
    {
        return new self('WhatsApp integration is disabled (WHATSAPP_ENABLED=false).');
    }
}
