<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a model_prices row came from. Bootstrap never writes prices, so there
 * is no "bootstrap" source: every production price is entered explicitly.
 */
enum ModelPriceSource: string
{
    case Manual = 'manual';
    case Import = 'import';
    case Seed = 'seed';
}
