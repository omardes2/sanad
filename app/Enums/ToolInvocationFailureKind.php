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

    /** An unexpected internal error (including the read-only guard tripping). */
    case Internal = 'internal';
}
