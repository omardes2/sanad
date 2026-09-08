<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a tool does to the world, declared in its definition and never inferred
 * at runtime (Phase F1). The class is the input to the retry, approval and
 * rate-limit rules that F3/F4 will enforce:
 *
 *  read           — reads only; retryable; never needs approval.
 *  write          — a local, reversible change through a domain service.
 *  external_write — leaves the system (a message, a call to a third party);
 *                   at most once, and may require approval by policy.
 *  irreversible   — cannot be undone; ALWAYS requires approval.
 */
enum ToolSideEffect: string
{
    case Read = 'read';

    case Write = 'write';

    case ExternalWrite = 'external_write';

    case Irreversible = 'irreversible';

    /** Approval is mandatory for this class whatever the definition says. */
    public function requiresApprovalAlways(): bool
    {
        return $this === self::Irreversible;
    }

    /** May a definition of this class ask for approval at all? A read never can. */
    public function mayRequireApproval(): bool
    {
        return $this !== self::Read;
    }

    /** Only these classes may be retried after a failure; the rest are at-most-once (F4). */
    public function retryable(): bool
    {
        return $this === self::Read || $this === self::Write;
    }

    public function label(): string
    {
        return match ($this) {
            self::Read => 'قراءة',
            self::Write => 'كتابة محلية',
            self::ExternalWrite => 'كتابة خارجية',
            self::Irreversible => 'غير قابل للتراجع',
        };
    }
}
