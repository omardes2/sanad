<?php

declare(strict_types=1);

namespace App\Enums;

enum ReplyMode: string
{
    case Text = 'text';
    case Voice = 'voice';
    case Auto = 'auto';
}
