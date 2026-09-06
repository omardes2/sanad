<?php

declare(strict_types=1);

namespace App\Enums;

/** How a plan price version came to exist. */
enum PlanPriceVersionSource: string
{
    /** First version of a plan that pre-dates the history, starting at the capture instant. */
    case Baseline = 'baseline';

    /** An administrative change on the Plans page. */
    case Admin = 'admin';
}
