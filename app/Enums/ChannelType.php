<?php

declare(strict_types=1);

namespace App\Enums;

enum ChannelType: string
{
    case WhatsApp = 'whatsapp';
    case Web = 'web';
}
