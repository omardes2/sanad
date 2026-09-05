<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a setting's effective value is resolved (decision A of Phase C):
 *
 *  Operational — database value, else the config default. The environment is
 *      NEVER consulted at runtime, so a stale value in .env cannot silently
 *      block what an admin set from Sanad Admin.
 *  Emergency   — an explicit environment override (captured at config time,
 *      see config('ai.overrides') / config('billing.overrides')) wins over
 *      the database value, which wins over the config default.
 */
enum SettingPrecedence: string
{
    case Operational = 'operational';
    case Emergency = 'emergency';
}
