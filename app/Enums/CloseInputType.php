<?php

declare(strict_types=1);

namespace App\Enums;

/** The kinds of inputs a period close freezes (finance_period_close_inputs.input_type). */
enum CloseInputType: string
{
    case Payment = 'payment';

    case Refund = 'refund';

    case GatewayFee = 'gateway_fee';

    case Reconciliation = 'reconciliation';

    case Adjustment = 'adjustment';
}
