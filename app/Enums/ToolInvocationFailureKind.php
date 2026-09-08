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

    /** An unexpected internal error (including the query guards tripping). */
    case Internal = 'internal';
}
