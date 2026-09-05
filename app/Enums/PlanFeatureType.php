<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a PlanFeature is valued in a plan's `features` JSON: a boolean on/off
 * switch, or an ordered tier (a small scale such as priority normal/high/highest).
 */
enum PlanFeatureType: string
{
    case Boolean = 'boolean';
    case Tier = 'tier';
}
