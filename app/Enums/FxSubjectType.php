<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\CostReconciliation;
use App\Models\CustomerPayment;
use App\Models\CustomerRefund;

/**
 * The subjects a reporting conversion may freeze, each with its policy date
 * (the date whose quote the finance user must choose) and its money scale.
 */
enum FxSubjectType: string
{
    case CustomerPayment = 'customer_payment';

    case CustomerRefund = 'customer_refund';

    case CostReconciliation = 'cost_reconciliation';

    /** @return class-string<CustomerPayment|CustomerRefund|CostReconciliation> */
    public function modelClass(): string
    {
        return match ($this) {
            self::CustomerPayment => CustomerPayment::class,
            self::CustomerRefund => CustomerRefund::class,
            self::CostReconciliation => CostReconciliation::class,
        };
    }

    public function scale(): int
    {
        return match ($this) {
            self::CustomerPayment, self::CustomerRefund => 2,
            self::CostReconciliation => 6,
        };
    }

    public function policyDateField(): string
    {
        return match ($this) {
            self::CustomerPayment => 'received_at',
            self::CustomerRefund => 'refunded_at',
            self::CostReconciliation => 'period_end',
        };
    }

    public function amountField(): string
    {
        return match ($this) {
            self::CustomerPayment, self::CustomerRefund => 'amount',
            self::CostReconciliation => 'reconciled_amount',
        };
    }
}
