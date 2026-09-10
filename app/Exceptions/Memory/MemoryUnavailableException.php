<?php

declare(strict_types=1);

namespace App\Exceptions\Memory;

use RuntimeException;

/**
 * Durable memory cannot operate because a key it needs is not configured
 * (Phase G).
 *
 * This is the FAIL-CLOSED state, and it is deliberate: a missing key means
 * nothing is written, nothing is read and nothing reaches a prompt. Falling
 * back to plaintext storage, or to an unkeyed fingerprint, would quietly
 * downgrade the guarantee the subscriber was given — so neither exists.
 *
 * The message is for the log. Callers see a bounded code, never this text.
 */
final class MemoryUnavailableException extends RuntimeException
{
    public static function missingKey(): self
    {
        return new self('ذاكرة سند غير متاحة: لا يوجد مفتاح تشفير مُهيَّأ (MEMORY_KEY).');
    }

    public static function missingFingerprintKey(): self
    {
        return new self('ذاكرة سند غير متاحة: لا يوجد مفتاح بصمة مُهيَّأ (MEMORY_FINGERPRINT_KEY).');
    }
}
