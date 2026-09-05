<?php

declare(strict_types=1);

namespace App\Data\Finance;

use App\Models\FinanceMrrSnapshot;
use App\Support\Billing\DecimalMath;
use Carbon\CarbonImmutable;

/**
 * The Calculated MRR picture at one instant: per-plan rows plus per-currency
 * totals. Currencies are NEVER added together (no FX in Phase D). Rows for
 * subscriptions without a plan (currency XXX, plan_key "none") are markers:
 * they never enter MRR / ARR / ARPU and never form a currency group — they
 * are reported separately by unassigned().
 */
final readonly class MrrSnapshotSet
{
    /**
     * @param  list<MrrPlanRow>  $rows
     */
    public function __construct(
        public CarbonImmutable $asOf,
        public int $calculationVersion,
        public array $rows,
    ) {}

    /**
     * @return array<string, array{mrr: string, arr: string, arpu: ?string, active: int, trialing: int, past_due: int}>
     */
    public function byCurrency(): array
    {
        $scale = 6;
        $totals = [];

        foreach ($this->rows as $row) {
            if ($row->currency === FinanceMrrSnapshot::NO_CURRENCY || $row->planKey === FinanceMrrSnapshot::PLAN_KEY_NONE) {
                continue; // marker rows are not a currency
            }

            $entry = $totals[$row->currency] ?? ['mrr' => 0, 'active' => 0, 'trialing' => 0, 'past_due' => 0];
            $entry['mrr'] += DecimalMath::toScaled($row->mrrNormalized, $scale);
            $entry['active'] += $row->activeCount;
            $entry['trialing'] += $row->trialingCount;
            $entry['past_due'] += $row->pastDueCount;
            $totals[$row->currency] = $entry;
        }

        ksort($totals);

        $out = [];

        foreach ($totals as $currency => $entry) {
            $out[$currency] = [
                'mrr' => DecimalMath::format($entry['mrr'], $scale),
                'arr' => DecimalMath::format(DecimalMath::mulDiv($entry['mrr'], 12, 1), $scale),
                'arpu' => $entry['active'] > 0 ? DecimalMath::format(DecimalMath::mulDiv($entry['mrr'], 1, $entry['active']), $scale) : null,
                'active' => $entry['active'],
                'trialing' => $entry['trialing'],
                'past_due' => $entry['past_due'],
            ];
        }

        return $out;
    }

    /**
     * Subscriptions without a plan (counts only — no price, no currency, no revenue).
     *
     * @return array{active: int, trialing: int, past_due: int}
     */
    public function unassigned(): array
    {
        $out = ['active' => 0, 'trialing' => 0, 'past_due' => 0];

        foreach ($this->rows as $row) {
            if ($row->currency === FinanceMrrSnapshot::NO_CURRENCY || $row->planKey === FinanceMrrSnapshot::PLAN_KEY_NONE) {
                $out['active'] += $row->activeCount;
                $out['trialing'] += $row->trialingCount;
                $out['past_due'] += $row->pastDueCount;
            }
        }

        return $out;
    }
}
