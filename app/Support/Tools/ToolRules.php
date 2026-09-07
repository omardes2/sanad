<?php

declare(strict_types=1);

namespace App\Support\Tools;

use App\Exceptions\Tools\ToolRuleException;

/**
 * The bounded-value rules of the tool layer (Phase F1).
 *
 * `evidenceRef` is the one place a human-supplied string reaches a consent
 * row, so it is bounded AND screened: no `@`, no run of 7+ digits, no control
 * characters — an email address or a phone number cannot be stored as
 * "evidence", by construction and by test. Everything else on the row is an
 * enum or a number.
 */
final class ToolRules
{
    public const MAX_REF = 191;

    /** A ticket / policy / message reference: bounded, printable, and provably not an email or a phone number. */
    public static function evidenceRef(?string $value, string $rule = 'evidence_ref'): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (mb_strlen($value) > self::MAX_REF) {
            throw ToolRuleException::of($rule, 'مرجع الدليل حتى '.self::MAX_REF.' حرفًا.');
        }

        if (preg_match('/^[\p{L}\p{N}_\-.:\/#]+$/u', $value) !== 1) {
            throw ToolRuleException::of($rule, 'مرجع الدليل يقبل حروفًا وأرقامًا و _ - . : / # فقط.');
        }

        if (str_contains($value, '@') || preg_match('/[0-9]{7,}/', $value) === 1) {
            throw ToolRuleException::of($rule, 'مرجع الدليل لا يقبل بريدًا أو رقم هاتف — استعمل مرجعًا (ticket:123).');
        }

        return $value;
    }

    /** A subscriber id is a positive integer, never a name or a phone number. */
    public static function subscriberId(int $value, string $rule = 'subscriber_id'): int
    {
        if ($value < 1) {
            throw ToolRuleException::of($rule, 'معرّف المشترك يجب أن يكون رقمًا صحيحًا موجبًا.');
        }

        return $value;
    }

    /** The version the caller says it saw: 0 means "no consent row at all". */
    public static function expectedVersion(int $value, string $rule = 'expected_version'): int
    {
        if ($value < 0) {
            throw ToolRuleException::of($rule, 'النسخة المتوقعة لا تكون سالبة (0 = لا موافقة بعد).');
        }

        return $value;
    }
}
