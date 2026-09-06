<?php

declare(strict_types=1);

namespace App\Exceptions\Close;

use RuntimeException;

/** Preflight found blocking conditions: the month cannot be closed. Nothing written. */
final class CloseBlockedException extends RuntimeException
{
    /**
     * @param  list<string>  $conditions  blocking condition codes, e.g. FEES_INCOMPLETE, RECONCILIATION_MISSING (provider:groq)
     */
    public function __construct(public readonly array $conditions)
    {
        parent::__construct('لا يمكن إقفال الشهر: '.implode('؛ ', $conditions));
    }
}
