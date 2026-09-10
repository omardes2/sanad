<?php

declare(strict_types=1);

namespace App\Data\Launch;

/**
 * One labelled fact under a launch gate.
 *
 * PRIVACY: a detail carries a LABEL and a short rendered VALUE, and the value
 * is produced by the check that owns it. No check may put a secret here — not a
 * key, not a token, not a credential, not a fingerprint. The readiness page
 * renders presence booleans and identifiers that are safe by construction (a
 * key *id*, a template *name*), never a secret value. Tests assert this.
 */
final readonly class GateDetail
{
    private function __construct(
        public string $label,
        public string $value,
        /** Presentation only: 'ok' | 'bad' | 'unknown' | 'plain'. Carries no authority. */
        public string $tone,
    ) {}

    public static function ok(string $label, string $value): self
    {
        return new self($label, $value, 'ok');
    }

    public static function bad(string $label, string $value): self
    {
        return new self($label, $value, 'bad');
    }

    public static function unknown(string $label, string $value): self
    {
        return new self($label, $value, 'unknown');
    }

    public static function plain(string $label, string $value): self
    {
        return new self($label, $value, 'plain');
    }

    /** A yes/no fact rendered as a presence boolean — the safe way to report configuration. */
    public static function boolean(string $label, bool $present, string $yes = 'مضبوط', string $no = 'غير مضبوط'): self
    {
        return $present ? self::ok($label, $yes) : self::bad($label, $no);
    }
}
