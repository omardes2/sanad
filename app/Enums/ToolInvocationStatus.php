<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The lifecycle of ONE tool invocation (Phase F2).
 *
 * F2 executes synchronous READ tools only, so the states are exactly the ones
 * something in F2 can actually produce. There is deliberately no `cancelled`
 * (nothing can cancel a synchronous read) and no `awaiting_approval` (a read
 * can never require approval by the F1 contract); both arrive with the phase
 * that introduces a producer for them.
 *
 * IN FLIGHT, REPLAY and CONFLICT are NOT states: they are outcomes of a claim
 * against an existing invocation, and none of them changes a stored row.
 */
enum ToolInvocationStatus: string
{
    /** Claimed: the identity exists, the input is canonical and hashed. Nothing ran. */
    case Planned = 'planned';

    /** Consent (and the operator gate, when one applies) passed. Nothing ran yet. */
    case Authorized = 'authorized';

    /** The read is executing right now. */
    case Running = 'running';

    case Succeeded = 'succeeded';

    case Failed = 'failed';

    /** Refused before anything ran — no execution, no usage. */
    case Refused = 'refused';

    case TimedOut = 'timed_out';

    /** A terminal state accepts no further transition, ever. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed, self::Refused, self::TimedOut => true,
            self::Planned, self::Authorized, self::Running => false,
        };
    }

    /** Did this state consume execution work (and therefore a usage row)? */
    public function consumedExecution(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed, self::TimedOut => true,
            default => false,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'مخطَّط',
            self::Authorized => 'مُصرَّح',
            self::Running => 'قيد التنفيذ',
            self::Succeeded => 'نجح',
            self::Failed => 'فشل',
            self::Refused => 'مرفوض',
            self::TimedOut => 'انتهت مهلته',
        };
    }

    /**
     * Options for an admin dropdown: value => label.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
