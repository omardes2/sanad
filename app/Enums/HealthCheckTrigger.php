<?php

declare(strict_types=1);

namespace App\Enums;

enum HealthCheckTrigger: string
{
    case Manual = 'manual';
    case Scheduled = 'scheduled';
}
