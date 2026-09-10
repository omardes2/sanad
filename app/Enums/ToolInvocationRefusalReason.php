<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why an invocation was refused BEFORE anything ran (Phase F2).
 *
 * Only the first two ever reach a stored row: they are decided after the claim
 * and are recorded as `planned → refused` / `authorized → refused`. The rest
 * happen before an invocation can legitimately be claimed, so they are
 * returned to the caller and never persisted as a malformed row.
 */
enum ToolInvocationRefusalReason: string
{
    /** The subscriber never granted the capability (no row = NOT GRANTED). */
    case NotGranted = 'not_granted';

    /** Consent was withdrawn between authorization and execution. */
    case ConsentRevoked = 'consent_revoked';

    case UnknownTool = 'unknown_tool';

    case UnknownVersion = 'unknown_version';

    case InvalidInput = 'invalid_input';

    /**
     * The side-effect class is not executable in this phase — F3-V1 runs `read`
     * and local `write` only, so `external_write` and `irreversible` land here.
     * Decided BEFORE any claim and BEFORE the approval check, so the class
     * always answers first and one condition never masks the other: an
     * `external_write` that also requires approval is
     * `side_effect_not_executable`, never `approval_required`.
     */
    case SideEffectNotExecutable = 'side_effect_not_executable';

    /**
     * Phase F3-V1 — the class IS executable, but the definition requires
     * approval and no approval mechanism exists yet. Fails closed before any
     * claim; the real server-bound, single-use, expiring approval arrives with
     * its own phase.
     */
    case ApprovalRequired = 'approval_required';

    case SubscriberMissing = 'subscriber_missing';

    /**
     * Phase G — the tool requires a server-verifiable EXPLICIT instruction on
     * the inbound message being processed, and there is none. A model proposing
     * a memory write is a suggestion, not authority: «أنا بحب القهوة سادة» is a
     * statement, «احفظ إني بحب القهوة سادة» is an instruction. Decided after
     * the claim, so the attempt is recorded rather than lost.
     */
    case ExplicitIntentMissing = 'explicit_intent_missing';

    /**
     * Is this reason decided after a claim, and therefore recorded on the
     * invocation? `side_effect_not_executable` appears on BOTH sides: it is the
     * pre-claim answer for a non-read candidate (no row at all), and the
     * terminal answer for the unreachable case of a `read` with no handler on a
     * slot this process had just claimed.
     */
    public function isPersisted(): bool
    {
        return $this === self::NotGranted
            || $this === self::ConsentRevoked
            || $this === self::ExplicitIntentMissing
            || $this === self::SideEffectNotExecutable;
    }

    public function label(): string
    {
        return match ($this) {
            self::NotGranted => 'لا توجد موافقة',
            self::ConsentRevoked => 'سُحبت الموافقة أثناء التنفيذ',
            self::UnknownTool => 'أداة غير معروفة',
            self::UnknownVersion => 'إصدار غير معروف',
            self::InvalidInput => 'مدخل غير صالح',
            self::SideEffectNotExecutable => 'أثر جانبي غير قابل للتنفيذ',
            self::ApprovalRequired => 'يحتاج موافقة',
            self::SubscriberMissing => 'لا يوجد مشترك',
            self::ExplicitIntentMissing => 'لا يوجد طلب صريح',
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
