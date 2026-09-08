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

    /**
     * A BOUNDED list of rows, each row itself a closed schema of SCALAR fields
     * (Phase F4). Still no opaque blob: the row shape is declared, the row count
     * has a maximum, and a list may never contain another list — so the depth is
     * one, always, and the size of any payload stays provably bounded.
     */
    case ListOfRows = 'list_of_rows';

    /** Can a nested row declare this type? A list may not contain a list. */
    public function isScalar(): bool
    {
        return $this !== self::ListOfRows;
    }
}
