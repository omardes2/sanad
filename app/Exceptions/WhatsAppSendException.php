<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when delivering a message to WhatsApp fails. Messages are safe by
 * construction: they carry only a short reason / HTTP status, NEVER the
 * access token, recipient phone number, or message body.
 *
 * The `kind` distinguishes the three outcomes a caller may legitimately act
 * on, and only one of them is a proven non-delivery:
 *
 *   rejected  — the provider positively rejected it. Not delivered.
 *   transient — throttled or a server-side error. We cannot prove either way.
 *   network   — no usable answer at all. We cannot prove either way.
 *   malformed — a success response we cannot read. We cannot prove either way.
 *
 * Anything other than `rejected` must be treated as UNKNOWN by a proactive
 * sender: never as a confirmed failure, and never as confirmed zero cost.
 */
class WhatsAppSendException extends RuntimeException
{
    public const KIND_NETWORK = 'network';

    public const KIND_TRANSIENT = 'transient';

    public const KIND_REJECTED = 'rejected';

    public const KIND_MALFORMED = 'malformed';

    private function __construct(
        string $message,
        public readonly string $kind,
        public readonly ?int $status = null,
    ) {
        parent::__construct($message);
    }

    public static function network(): self
    {
        return new self('Network error while contacting the WhatsApp Cloud API.', self::KIND_NETWORK);
    }

    public static function transient(int $status): self
    {
        return new self("Transient WhatsApp Cloud API error (HTTP {$status}).", self::KIND_TRANSIENT, $status);
    }

    public static function rejected(int $status): self
    {
        return new self("WhatsApp Cloud API rejected the message (HTTP {$status}).", self::KIND_REJECTED, $status);
    }

    public static function malformedResponse(): self
    {
        return new self('WhatsApp Cloud API returned an unexpected response shape.', self::KIND_MALFORMED);
    }

    /**
     * True only when the provider positively refused the message, so a caller
     * may record a confirmed non-delivery.
     */
    public function isPermanentRejection(): bool
    {
        return $this->kind === self::KIND_REJECTED;
    }
}
