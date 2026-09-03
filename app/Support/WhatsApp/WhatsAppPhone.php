<?php

declare(strict_types=1);

namespace App\Support\WhatsApp;

/**
 * WhatsApp sends the sender's number as bare digits (no "+"). This normalizes
 * it to E.164 and offers a redacted form for logs (full numbers are never
 * logged).
 */
final class WhatsAppPhone
{
    /**
     * Normalize bare provider digits to E.164 (+<digits>), or null if invalid.
     */
    public static function toE164(string $from): ?string
    {
        $digits = preg_replace('/\D+/', '', $from) ?? '';

        // E.164: 8–15 digits, first digit non-zero.
        if (! preg_match('/^[1-9]\d{7,14}$/', $digits)) {
            return null;
        }

        return '+'.$digits;
    }

    /**
     * Redact a phone number for logging: keep the last 4 digits only.
     */
    public static function redact(?string $phone): string
    {
        if ($phone === null || $phone === '') {
            return '(none)';
        }

        $tail = substr($phone, -4);

        return '***'.$tail;
    }
}
