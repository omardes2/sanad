<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Finance\CostProducers;

/** The three cost components of the ledger, each reconciled on its own (Phase E2). */
enum CostComponent: string
{
    case Provider = 'provider';

    case Communication = 'communication';

    case External = 'external';

    /** The usage_events column carrying this component's calculated cost. */
    public function ledgerColumn(): string
    {
        return match ($this) {
            self::Provider => 'provider_cost',
            self::Communication => 'communication_cost',
            self::External => 'external_cost',
        };
    }

    /** Whether any code path records this component in the ledger at all. */
    public function hasProducer(): bool
    {
        return match ($this) {
            self::Provider => CostProducers::PROVIDER,
            self::Communication => CostProducers::COMMUNICATION,
            self::External => CostProducers::EXTERNAL,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Provider => 'مزوّد الذكاء',
            self::Communication => 'التواصل',
            self::External => 'خدمات خارجية',
        };
    }
}
