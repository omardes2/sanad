<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\CostAdjustment;
use App\Models\CostReconciliation;
use App\Models\CustomerPayment;
use App\Models\CustomerRefund;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The subjects a reporting conversion may freeze, each with its policy date
 * (the date whose quote the finance user must choose) and its money scale.
 */
enum FxSubjectType: string
{
    case CustomerPayment = 'customer_payment';

    case CustomerRefund = 'customer_refund';

    case CostReconciliation = 'cost_reconciliation';

    /** Phase E4: a post-reconciliation adjustment, converted on its reconciliation's period_end policy date. */
    case CostAdjustment = 'cost_adjustment';

    /** @return class-string<CustomerPayment|CustomerRefund|CostReconciliation|CostAdjustment> */
    public function modelClass(): string
    {
        return match ($this) {
            self::CustomerPayment => CustomerPayment::class,
            self::CustomerRefund => CustomerRefund::class,
            self::CostReconciliation => CostReconciliation::class,
            self::CostAdjustment => CostAdjustment::class,
        };
    }

    public function scale(): int
    {
        return match ($this) {
            self::CustomerPayment, self::CustomerRefund => 2,
            self::CostReconciliation, self::CostAdjustment => 6,
        };
    }

    /** The policy date whose quote a conversion must use (UTC). */
    public function policyDate(Model $subject): CarbonImmutable
    {
        return match ($this) {
            self::CustomerPayment => CarbonImmutable::instance($subject->getAttribute('received_at'))->utc(),
            self::CustomerRefund => CarbonImmutable::instance($subject->getAttribute('refunded_at'))->utc(),
            self::CostReconciliation => CarbonImmutable::instance($subject->getAttribute('period_end'))->utc(),
            self::CostAdjustment => CarbonImmutable::instance(CostReconciliation::query()->whereKey($subject->getAttribute('cost_reconciliation_id'))->value('period_end'))->utc(),
        };
    }

    public function amountField(): string
    {
        return match ($this) {
            self::CustomerPayment, self::CustomerRefund, self::CostAdjustment => 'amount',
            self::CostReconciliation => 'reconciled_amount',
        };
    }
}
