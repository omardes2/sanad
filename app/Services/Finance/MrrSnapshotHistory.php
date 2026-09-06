<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Data\Finance\MrrHistoryDay;
use App\Data\Finance\MrrHistorySeries;
use App\Models\FinanceMrrSnapshot;
use App\Support\Billing\DecimalMath;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Read-only reader of `finance_mrr_snapshots` (Phase D2): the HISTORICAL MRR
 * RUN-RATE as it was frozen day by day by `sanad:finance:snapshot`.
 *
 * What this is NOT: revenue history. A snapshot says "the list-price run-rate
 * as of that day"; it does not say what was earned or collected that day.
 * Consequently this class never sums days, never multiplies a run-rate by a
 * number of days and never meets usage cost. Marker rows (currency XXX /
 * plan_key none) are excluded; missing days are reported as such — no
 * interpolation, no back-fill.
 */
final class MrrSnapshotHistory
{
    public const MAX_DAYS = FinanceQuery::MAX_TREND_DAYS;

    public function series(CarbonImmutable $from, CarbonImmutable $to): MrrHistorySeries
    {
        $from = $from->setTimezone('UTC')->startOfDay();
        $to = $to->setTimezone('UTC')->startOfDay();

        if ($to < $from) {
            throw new InvalidArgumentException('نهاية النطاق يجب أن تكون بعد بدايته.');
        }

        if ($from->diffInDays($to) > self::MAX_DAYS) {
            throw new InvalidArgumentException('النطاق الأقصى '.self::MAX_DAYS.' يومًا.');
        }

        $first = FinanceMrrSnapshot::query()->min('snapshot_date');
        $firstDate = $first === null ? null : substr((string) $first, 0, 10);

        $rows = FinanceMrrSnapshot::query()
            ->where('snapshot_date', '>=', $from->toDateString())
            ->where('snapshot_date', '<=', $to->toDateString())
            ->where('plan_key', '!=', FinanceMrrSnapshot::PLAN_KEY_NONE)
            ->where('currency', '!=', FinanceMrrSnapshot::NO_CURRENCY)
            ->orderBy('snapshot_date')
            ->get();

        /** @var array<string, array<string, array{mrr: int, active: int, trialing: int, past_due: int}>> $byDay */
        $byDay = [];
        /** @var array<string, string> $capturedAt */
        $capturedAt = [];
        $currencies = [];

        foreach ($rows as $row) {
            $date = substr((string) $row->snapshot_date, 0, 10);
            $currency = (string) $row->currency;
            $entry = $byDay[$date][$currency] ?? ['mrr' => 0, 'active' => 0, 'trialing' => 0, 'past_due' => 0];
            $entry['mrr'] += DecimalMath::toScaled((string) $row->mrr_normalized, RevenueNormalizer::SCALE);
            $entry['active'] += $row->active_count;
            $entry['trialing'] += $row->trialing_count;
            $entry['past_due'] += $row->past_due_count;
            $byDay[$date][$currency] = $entry;
            $capturedAt[$date] = $row->captured_at?->toIso8601String();
            $currencies[$currency] = true;
        }

        // Marker-only days (a day with no subscriptions) still count as captured.
        $markerDays = FinanceMrrSnapshot::query()
            ->where('snapshot_date', '>=', $from->toDateString())
            ->where('snapshot_date', '<=', $to->toDateString())
            ->where('plan_key', FinanceMrrSnapshot::PLAN_KEY_NONE)
            ->get(['snapshot_date', 'captured_at']);

        foreach ($markerDays as $marker) {
            $date = substr((string) $marker->snapshot_date, 0, 10);
            $byDay[$date] ??= [];
            $capturedAt[$date] ??= $marker->captured_at?->toIso8601String();
        }

        $days = [];

        for ($cursor = $from; $cursor <= $to; $cursor = $cursor->addDay()) {
            $date = $cursor->toDateString();

            if ($firstDate === null || $date < $firstDate) {
                $days[] = new MrrHistoryDay($date, MrrHistoryDay::NOT_AVAILABLE, [], null);

                continue;
            }

            if (! array_key_exists($date, $byDay)) {
                $days[] = new MrrHistoryDay($date, MrrHistoryDay::NOT_CAPTURED, [], null);

                continue;
            }

            $perCurrency = [];

            foreach ($byDay[$date] as $currency => $entry) {
                $perCurrency[$currency] = [
                    'mrr' => DecimalMath::format($entry['mrr'], RevenueNormalizer::SCALE),
                    'active' => $entry['active'],
                    'trialing' => $entry['trialing'],
                    'past_due' => $entry['past_due'],
                ];
            }

            ksort($perCurrency);
            $days[] = new MrrHistoryDay($date, MrrHistoryDay::CAPTURED, $perCurrency, $capturedAt[$date] ?? null);
        }

        $list = array_keys($currencies);
        sort($list);

        return new MrrHistorySeries($from->toDateString(), $to->toDateString(), $firstDate, $days, $list);
    }
}
