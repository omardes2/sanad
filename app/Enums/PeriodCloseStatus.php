<?php

declare(strict_types=1);

namespace App\Enums;

/** A finance_period_closes row: a frozen close, or the record of reopening one. */
enum PeriodCloseStatus: string
{
    case Closed = 'closed';

    case Reopened = 'reopened';
}
