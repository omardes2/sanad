<?php

declare(strict_types=1);

namespace App\Support\WhatsApp;

/**
 * Validates Meta's `X-Hub-Signature-256` header against the RAW request body
 * using HMAC-SHA256 and a timing-safe comparison. Never logs or exposes the
 * app secret or the body.
 */
final class WhatsAppSignature
{
    /**
     * @param  string  $rawBody  the exact bytes of the request body
     * @param  string|null  $header  value of the X-Hub-Signature-256 header
     */
    public static function isValid(string $rawBody, ?string $header, string $appSecret): bool
    {
        if ($header === null || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        $provided = substr($header, strlen('sha256='));

        if ($provided === '' || ! ctype_xdigit($provided)) {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $appSecret);

        return hash_equals($expected, strtolower($provided));
    }
}
