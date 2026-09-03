<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when delivering a message to WhatsApp fails. Messages are safe by
 * construction: they carry only a short reason / HTTP status, NEVER the
 * access token, recipient phone number, or message body.
 */
class WhatsAppSendException extends RuntimeException
{
    public static function network(): self
    {
        return new self('Network error while contacting the WhatsApp Cloud API.');
    }

    public static function transient(int $status): self
    {
        return new self("Transient WhatsApp Cloud API error (HTTP {$status}).");
    }

    public static function rejected(int $status): self
    {
        return new self("WhatsApp Cloud API rejected the message (HTTP {$status}).");
    }

    public static function malformedResponse(): self
    {
        return new self('WhatsApp Cloud API returned an unexpected response shape.');
    }
}
