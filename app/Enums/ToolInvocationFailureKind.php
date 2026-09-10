<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why an invocation that HAD STARTED did not succeed (Phase F2) — a closed
 * list, never free text, so nothing a tool or a model produced is echoed into
 * the record.
 */
enum ToolInvocationFailureKind: string
{
    /** The tool itself raised a domain error. */
    case ToolError = 'tool_error';

    /** The tool returned something the declared OUTPUT schema refuses; the result is discarded. */
    case InvalidOutput = 'invalid_output';

    /** The execution exceeded the timeout declared by this tool version. */
    case Timeout = 'timeout';

    /**
     * Phase F3-V1 — the row a write tool named does not exist, OR it is not
     * this subscriber's. The two are deliberately the same answer, so a tool
     * can never be used to discover what somebody else owns.
     */
    case NotFound = 'not_found';

    /** Phase F3-V1 — the row is the subscriber's, but its state does not allow the change. */
    case Rule = 'rule';

    /**
     * Phase G — the write named content durable memory refuses to hold: a
     * structured identifier (a card, an IBAN, a credential, a national id).
     * The value itself is never stored, logged or echoed back.
     */
    case SensitiveContent = 'sensitive_content';

    /**
     * Phase G — the subscriber's durable memory is full. Nothing is evicted to
     * make room: an explicit memory is removed only when they explicitly forget
     * one, so a new memory at capacity is refused instead.
     */
    case MemoryCapacityReached = 'memory_capacity_reached';

    /**
     * Phase G — the request matched more than one row and the server will not
     * guess which one was meant. Nothing was changed.
     */
    case Ambiguous = 'ambiguous';

    /**
     * Phase G — durable memory has no key configured, so it can neither seal
     * nor fingerprint. Failing closed is deliberate: plaintext memory and an
     * unkeyed fingerprint are not acceptable fallbacks.
     */
    case MemoryUnavailable = 'memory_unavailable';

    /** An unexpected internal error (including the query guards tripping). */
    case Internal = 'internal';

    public function label(): string
    {
        return match ($this) {
            self::ToolError => 'خطأ داخل الأداة',
            self::InvalidOutput => 'مخرَج لا يطابق العقد',
            self::Timeout => 'انتهت المهلة',
            self::NotFound => 'غير موجود',
            self::Rule => 'مخالفة قاعدة نطاق',
            self::SensitiveContent => 'محتوى حسّاس',
            self::MemoryCapacityReached => 'بلغت الذاكرة سقفها',
            self::Ambiguous => 'وصف ملتبس',
            self::MemoryUnavailable => 'الذاكرة غير متاحة',
            self::Internal => 'خطأ داخلي',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
