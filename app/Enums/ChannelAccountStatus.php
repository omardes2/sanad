<?php

declare(strict_types=1);

namespace App\Enums;

enum ChannelAccountStatus: string
{
    case Active = 'active';
    case Disconnected = 'disconnected';
    case Blocked = 'blocked';
}
