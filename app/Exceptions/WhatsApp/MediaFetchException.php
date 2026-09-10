<?php

declare(strict_types=1);

namespace App\Exceptions\WhatsApp;

use RuntimeException;

/**
 * Fetching inbound media from the WhatsApp Cloud API failed.
 *
 * Messages are safe by construction: a short reason and an HTTP status, never
 * the access token, the signed media URL (which is itself a credential), the
 * subscriber's number, or any bytes.
 *
 * The `kind` is what the caller acts on, and the distinction that matters is
 * PERMANENT versus UNKNOWN:
 *
 *   gone      — the provider says this media no longer exists. Permanent:
 *               WhatsApp media expires, and no retry can bring it back.
 *   too_large — the file exceeds the configured ceiling. Permanent: the same
 *               file will exceed it again.
 *   rejected  — the provider refused the request (4xx). Permanent.
 *   transient — throttled, a server error, or no answer at all. UNKNOWN, and
 *               a retry is legitimate.
 *
 * None of these mean the TRANSCRIPTION provider was reached — this is the step
 * before it — so none of them can ever imply a paid request happened.
 */
final class MediaFetchException extends RuntimeException
{
    public const KIND_GONE = 'gone';

    public const KIND_TOO_LARGE = 'too_large';

    public const KIND_REJECTED = 'rejected';

    public const KIND_TRANSIENT = 'transient';

    private function __construct(
        string $message,
        public readonly string $kind,
        public readonly ?int $status = null,
    ) {
        parent::__construct($message);
    }

    public static function gone(int $status): self
    {
        return new self("WhatsApp media is no longer available (HTTP {$status}).", self::KIND_GONE, $status);
    }

    public static function tooLarge(int $bytes, int $limit): self
    {
        return new self("WhatsApp media is larger than the configured limit ({$bytes} > {$limit} bytes).", self::KIND_TOO_LARGE);
    }

    public static function rejected(int $status): self
    {
        return new self("WhatsApp Cloud API rejected the media request (HTTP {$status}).", self::KIND_REJECTED, $status);
    }

    public static function transient(?int $status = null): self
    {
        return new self(
            $status === null
                ? 'Network error while fetching WhatsApp media.'
                : "Transient WhatsApp media error (HTTP {$status}).",
            self::KIND_TRANSIENT,
            $status,
        );
    }

    public static function malformed(): self
    {
        return new self('WhatsApp Cloud API returned an unexpected media response shape.', self::KIND_TRANSIENT);
    }

    /** A retry could plausibly succeed; nothing here is proven permanent. */
    public function retryable(): bool
    {
        return $this->kind === self::KIND_TRANSIENT;
    }
}
