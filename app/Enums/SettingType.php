<?php

declare(strict_types=1);

namespace App\Enums;

enum SettingType: string
{
    case String = 'string';
    case Text = 'text';
    case Integer = 'integer';
    case Float = 'float';
    case Boolean = 'boolean';
    case Enum = 'enum';
    /** Plain text with an explicit placeholder allowlist (strtr only — no Blade, no PHP). */
    case Template = 'template';
}
