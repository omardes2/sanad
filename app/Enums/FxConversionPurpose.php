<?php

declare(strict_types=1);

namespace App\Enums;

/** Why a frozen reporting conversion exists. E3: reporting only (reconciliation evidence FX lives on the allocation rows). */
enum FxConversionPurpose: string
{
    case Reporting = 'reporting';
}
