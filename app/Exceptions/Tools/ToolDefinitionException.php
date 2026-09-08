<?php

declare(strict_types=1);

namespace App\Exceptions\Tools;

use LogicException;

/**
 * A tool definition or the registry itself is invalid: a duplicate key@version,
 * an unknown key, a schema that is not closed, an impossible side-effect /
 * approval combination. The registry FAILS CLOSED — this is thrown while the
 * registry is being built (boot and test time), never swallowed, so a bad
 * definition can never silently override a good one.
 */
final class ToolDefinitionException extends LogicException
{
    public static function of(string $message): self
    {
        return new self($message);
    }
}
