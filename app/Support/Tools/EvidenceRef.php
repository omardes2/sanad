<?php

declare(strict_types=1);

namespace App\Support\Tools;

use App\Exceptions\Tools\ToolRuleException;

/**
 * An OPAQUE MACHINE REFERENCE — the only kind of "evidence" a consent record
 * accepts (Phase F1).
 *
 * It is not a free-text field with a character filter: it is a closed grammar
 * of `<prefix>:<value>` with an allowlisted prefix, so there is nowhere for a
 * name, an email, a phone number, a sentence or a quoted message to go. A
 * value that is not exactly one of these shapes is refused, and when there is
 * no reference the row stores NULL rather than inventing text.
 *
 *   message:<id>          the inbound/outbound message the decision came from
 *   conversation:<id>     the conversation it was decided in
 *   admin_action:<id>     an administrative action record
 *   policy:<code>         a policy code (lower-case, bounded)
 *
 * ASCII only, no whitespace, at most 64 characters. `reason_code` carries the
 * semantic reason; this carries only where to look it up.
 */
final readonly class EvidenceRef
{
    public const MAX = 64;

    /** The closed list of prefixes. Adding one is a code change, reviewed like any other. */
    public const PREFIXES = ['message', 'conversation', 'admin_action', 'policy'];

    /** `message:1`, `conversation:42`, `admin_action:7` — a positive identifier, nothing else. */
    private const ID = '/^[1-9][0-9]{0,17}$/';

    /** `policy:gdpr-erasure` — a lower-case bounded code, never prose. */
    private const CODE = '/^[a-z0-9][a-z0-9_\-]{0,31}$/';

    private function __construct(public string $prefix, public string $id) {}

    /**
     * @throws ToolRuleException
     */
    public static function of(string $raw, string $rule = 'evidence_ref'): self
    {
        $refuse = static fn (string $why): ToolRuleException => ToolRuleException::of($rule, "مرجع الدليل {$why} — استعمل ".implode(' | ', array_map(static fn (string $p): string => $p.':<id>', self::PREFIXES)).'.');

        if ($raw === '' || mb_strlen($raw) > self::MAX) {
            throw $refuse('يجب أن يكون غير فارغ وبحدّ '.self::MAX.' حرفًا');
        }

        // ASCII only and no whitespace at all: a human sentence cannot survive this line.
        if (preg_match('/^[\x21-\x7E]+$/', $raw) !== 1) {
            throw $refuse('يقبل ASCII بلا فراغات فقط');
        }

        $parts = explode(':', $raw);

        if (count($parts) !== 2) {
            throw $refuse('يجب أن يكون بصيغة prefix:value');
        }

        [$prefix, $value] = $parts;

        if (! in_array($prefix, self::PREFIXES, true)) {
            throw $refuse("لا يقبل البادئة [{$prefix}]");
        }

        $pattern = $prefix === 'policy' ? self::CODE : self::ID;

        if (preg_match($pattern, $value) !== 1) {
            throw $refuse('قيمته ليست معرّفًا صالحًا لهذه البادئة');
        }

        return new self($prefix, $value);
    }

    /** Null stays null: no reference is recorded as none, never as invented text. */
    public static function optional(?string $raw, string $rule = 'evidence_ref'): ?self
    {
        return $raw === null ? null : self::of($raw, $rule);
    }

    public function value(): string
    {
        return $this->prefix.':'.$this->id;
    }

    public function __toString(): string
    {
        return $this->value();
    }
}
