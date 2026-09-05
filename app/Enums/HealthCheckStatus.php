<?php

declare(strict_types=1);

namespace App\Enums;

enum HealthCheckStatus: string
{
    case Ok = 'ok';
    case Degraded = 'degraded';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'سليم',
            self::Degraded => 'متدهور',
            self::Failed => 'فشل',
            self::Skipped => 'متخطّى',
        };
    }
}
