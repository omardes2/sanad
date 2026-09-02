<?php

declare(strict_types=1);

namespace App\Enums;

enum MessageType: string
{
    case Text = 'text';
    case Audio = 'audio';
    case Image = 'image';
    case Document = 'document';
    case Location = 'location';
    case Interactive = 'interactive';
    case System = 'system';
}
