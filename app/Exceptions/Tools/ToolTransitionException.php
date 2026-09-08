<?php

declare(strict_types=1);

namespace App\Exceptions\Tools;

use App\Enums\ToolInvocationStatus;
use RuntimeException;

/**
 * A lifecycle move the transition table refuses (Phase F2). Nothing is written:
 * the loser of a settlement race sees the winner's terminal state and stops.
 */
final class ToolTransitionException extends RuntimeException
{
    public function __construct(
        public readonly ToolInvocationStatus $from,
        public readonly ToolInvocationStatus $to,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function of(ToolInvocationStatus $from, ToolInvocationStatus $to, string $message): self
    {
        return new self($from, $to, $message);
    }
}
