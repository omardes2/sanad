<?php

declare(strict_types=1);

namespace App\Data\Finance;

use App\Models\FinanceMrrSnapshot;

/**
 * Gross margin in Phase D: NOT AVAILABLE, by design, with the reasons named.
 *
 * There is no revenue history (no subscription state history, no plan-price
 * history, no payments) so no period's revenue can be stated, and therefore
 * no gross profit or margin — partial or otherwise. This object carries NO
 * number on purpose; it exists so the page and the CSV say why, consistently.
 * Phase E (reconciliation & payments) is where a figure can first appear.
 */
final readonly class GrossMarginStatus
{
    public const REVENUE_HISTORY_UNAVAILABLE = 'revenue_history_unavailable';

    public const UNPRICED_USAGE = 'unpriced_usage';

    public const INCOMPLETE_COST_COVERAGE = 'incomplete_cost_coverage';

    public const CURRENCY_MISMATCH = 'currency_mismatch';

    /**
     * @param  list<string>  $reasons
     */
    private function __construct(public array $reasons) {}

    public static function forWindow(CostTotals $totals, CostCoverage $coverage, MrrSnapshotSet $current): self
    {
        // Always true in Phase D: the platform cannot rebuild any period's revenue.
        $reasons = [self::REVENUE_HISTORY_UNAVAILABLE];

        if ($totals->unpricedRows > 0) {
            $reasons[] = self::UNPRICED_USAGE;
        }

        if (! $coverage->knownCostIsFullServiceCost()) {
            $reasons[] = self::INCOMPLETE_COST_COVERAGE;
        }

        foreach (array_keys($current->byCurrency()) as $currency) {
            if ($currency !== FinanceMrrSnapshot::NO_CURRENCY && strtoupper((string) $currency) !== strtoupper($totals->currency)) {
                $reasons[] = self::CURRENCY_MISMATCH;

                break;
            }
        }

        return new self($reasons);
    }

    /** Never true in Phase D. */
    public function isAvailable(): bool
    {
        return false;
    }

    public function label(): string
    {
        return 'NOT AVAILABLE — Phase E';
    }

    public static function reasonLabel(string $reason): string
    {
        return match ($reason) {
            self::REVENUE_HISTORY_UNAVAILABLE => 'لا يوجد تاريخ إيراد (لا سجل حالات اشتراك ولا تاريخ أسعار ولا مدفوعات)',
            self::UNPRICED_USAGE => 'استخدام غير مسعَّر داخل النطاق',
            self::INCOMPLETE_COST_COVERAGE => 'تغطية التكلفة غير مكتملة (تواصل/خارجي بلا مصدر)',
            self::CURRENCY_MISMATCH => 'اختلاف عملة الباقات عن عملة التكلفة',
            default => $reason,
        };
    }
}
