<?php

declare(strict_types=1);

namespace App\Support\Memory;

/**
 * Content durable memory REFUSES to hold (Phase G).
 *
 * WHAT THIS IS. A closed, code-declared list of STRUCTURED identifiers whose
 * shape is recognisable with certainty: payment instruments, credentials and
 * one-time codes, and national/passport identifiers. When one is present the
 * write is refused whole — the value is not stored, not logged, not echoed
 * back, and not partially redacted into the row.
 *
 * WHAT THIS IS NOT, stated plainly so nobody mistakes it for more. It is a
 * PATTERN LIST, not a classifier. It reliably catches things with a shape; it
 * does NOT reliably catch a sensitive fact written as ordinary Arabic prose —
 * a diagnosis, a salary, a religious or political affiliation described in
 * words has no shape to match. Claiming otherwise would be dishonest.
 *
 * The real protection is structural and sits elsewhere: V1 writes a memory ONLY
 * when the subscriber explicitly asked for it (`ExplicitMemoryIntent`), so
 * nothing is persisted that they did not choose to persist. This list is the
 * floor under that choice — it stops the cases where a subscriber pastes a card
 * number into a "احفظ ..." instruction without thinking about what it is.
 */
final class SensitiveContent
{
    /**
     * name => pattern. Names are for the tests and the documentation; a match
     * never travels anywhere with the value beside it.
     *
     * @var array<string, string>
     */
    private const PATTERNS = [
        // IBAN: two letters, two check digits, then 11–30 alphanumerics. Read
        // before the card pattern, whose digit run an IBAN would also satisfy.
        'iban' => '/\b[A-Z]{2}\d{2}[A-Z0-9]{11,30}\b/i',
        // 13–19 digits, optionally grouped — card PANs. Bounded so an ordinary
        // year or amount cannot match.
        'card' => '/(?:\d[ \-]?){12,18}\d/',
        // An explicit CVV / PIN / OTP / password / secret / token, with a value.
        'credential' => '/\b(cvv|cvc|pin|otp|password|passcode|secret|api[ _-]?key|token)\b\s*[:=]?\s*\S+/i',
        'credential_ar' => '/(كلمة السر|كلمة المرور|الرقم السري|رمز التحقق|رمز الأمان)\s*[:=]?\s*\S+/u',
        // Palestinian/most regional national IDs are 9 digits; passports are a
        // letter plus 7–8 digits. Both are matched only when announced.
        'national_id' => '/\b(هوية|رقم الهوية|national\s*id|id\s*number|passport|جواز)\b\D{0,12}[A-Z]?\d{7,10}\b/iu',
    ];

    /** Does this content carry a structured identifier memory must not hold? */
    public static function present(string $content): bool
    {
        return self::match($content) !== null;
    }

    /**
     * The NAME of the first pattern that matched, or null. The matched value is
     * never returned, so a caller cannot accidentally log it.
     */
    public static function match(string $content): ?string
    {
        foreach (self::PATTERNS as $name => $pattern) {
            if (preg_match($pattern, $content) === 1) {
                return $name;
            }
        }

        return null;
    }

    /** @return list<string> the pattern names, for documentation and tests */
    public static function names(): array
    {
        return array_keys(self::PATTERNS);
    }
}
