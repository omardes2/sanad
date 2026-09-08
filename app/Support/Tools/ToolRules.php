<?php

declare(strict_types=1);

namespace App\Support\Tools;

use App\Exceptions\Tools\ToolRuleException;

/**
 * The bounded-value rules of the tool layer (Phase F1).
 *
 * Nothing human-written reaches a consent row through here: the reason is a
 * closed enum, the evidence is an `EvidenceRef` value object with an
 * allowlisted prefix, and what is left is identifiers and versions.
 */
final class ToolRules
{
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
