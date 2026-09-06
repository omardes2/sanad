<?php

declare(strict_types=1);

namespace App\Enums;

/** How one fx_rates row was applied: 1 BASE = rate × QUOTE ⇒ direct = multiply (BASE→QUOTE), inverse = divide (QUOTE→BASE). */
enum FxDirection: string
{
    case Direct = 'direct';

    case Inverse = 'inverse';
}
