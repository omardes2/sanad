<?php

declare(strict_types=1);

namespace App\Support\Tools;

use App\Enums\ToolInvocationStatus;
use App\Exceptions\Tools\ToolTransitionException;

/**
 * The lifecycle of a tool invocation, as a CODE TABLE (Phase F2).
 *
 * The database stores the current status and the history of how it got there;
 * it never declares which moves are legal. This table does, it is exhaustive,
 * and it is the only thing `ToolInvocationStore` consults before writing:
 *
 *   (none)      → planned
 *   planned     → authorized | refused
 *   authorized  → running    | refused
 *   running     → succeeded  | failed | timed_out
 *
 * A TERMINAL state accepts nothing at all — that single rule is what makes the
 * terminal record (audit + usage) happen exactly once even when a sweeper and
 * the original worker settle the same row at the same moment.
 */
final class ToolInvocationTransitions
{
    /** @var array<string, list<string>> */
    private const ALLOWED = [
        'planned' => ['authorized', 'refused'],
        'authorized' => ['running', 'refused'],
        'running' => ['succeeded', 'failed', 'timed_out'],
        'succeeded' => [],
        'failed' => [],
        'refused' => [],
        'timed_out' => [],
    ];

    /** The one state a claim may create. */
    public static function initial(): ToolInvocationStatus
    {
        return ToolInvocationStatus::Planned;
    }

    public static function allows(ToolInvocationStatus $from, ToolInvocationStatus $to): bool
    {
        return in_array($to->value, self::ALLOWED[$from->value], true);
    }

    /**
     * @throws ToolTransitionException
     */
    public static function assert(ToolInvocationStatus $from, ToolInvocationStatus $to): void
    {
        if (self::allows($from, $to)) {
            return;
        }

        throw ToolTransitionException::of(
            $from,
            $to,
            $from->isTerminal()
                ? "الاستدعاء في حالة نهائية [{$from->value}] ولا يقبل أي انتقال."
                : "انتقال غير مسموح: [{$from->value}] ⇒ [{$to->value}]."
        );
    }

    /** @return list<array{0: ToolInvocationStatus, 1: ToolInvocationStatus}> every legal pair, for the matrix test */
    public static function pairs(): array
    {
        $pairs = [];

        foreach (self::ALLOWED as $from => $targets) {
            foreach ($targets as $to) {
                $pairs[] = [ToolInvocationStatus::from($from), ToolInvocationStatus::from($to)];
            }
        }

        return $pairs;
    }
}
