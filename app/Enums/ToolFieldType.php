<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The value shapes a tool contract may declare (Phase F1). Deliberately small
 * and boring: every one of them is a value the platform can validate and bound
 * on its own. There is no "any", no "object", no "raw" — a tool can never
 * accept an opaque blob that later gets interpreted.
 */
enum ToolFieldType: string
{
    case String = 'string';

    case Integer = 'integer';

    case Decimal = 'decimal';

    case Boolean = 'boolean';

    /** `YYYY-MM-DD`, UTC. */
    case Date = 'date';

    /** `YYYY-MM-DDTHH:MM`, UTC. */
    case DateTime = 'datetime';

    /** One of a closed list declared with the field. */
    case Enum = 'enum';
}
