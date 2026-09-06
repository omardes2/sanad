<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Data\Finance\CostBucket;
use App\Data\Finance\GrossMarginStatus;
use App\Data\Finance\MrrHistorySeries;
use App\Models\Plan;
use App\Models\UsageEvent;
use App\Services\Finance\FinanceQuery;
use App\Services\Finance\MrrCalculator;
use App\Services\Finance\MrrSnapshotHistory;
use App\Services\Fx\ReportingView;
use App\Services\Payments\CashCollectedQuery;
use App\Services\Reporting\FrozenCloseReader;
use App\Services\Reporting\ReconciledMonthSeries;
use App\Services\Usage\UsageQuery;
use App\Support\Billing\DecimalMath;
use App\Support\Rbac\Permission;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Finance overview (Phase D2 + E5.1). Strict RBAC: `finance.view` opens the
 * page (route middleware AND mount); CSV links need `finance.export`; a link
 * to a subscriber's detail page appears only with `subscribers.view`. No PII:
 * subscribers are shown as their internal id. Read-only: nothing here writes.
 *
 * Bands that must never be read as one — three financial vocabularies stay
 * visibly separate and no card or total mixes them:
 *  1. Current Subscription Run-rate — as of now (MRR/ARR/ARPU per currency;
 *     the date window does NOT apply to it);
 *  2. CALCULATED — Usage & Cost Analysis for the selected UTC window (known
 *     cost, unpriced/unknown, coverage, breakdowns); gross margin NOT AVAILABLE;
 *  3. CASH — the selected UTC window, LIVE / CURRENT: Gross Cash Collected,
 *     Refunds, Net Cash, Gateway Fees or FEES UNKNOWN per native currency, and
 *     reporting-currency totals only when every line is NATIVE or CONVERTED;
 *  4. RECONCILED — one row per calendar month UTC overlapping the window in the
 *     current reporting currency: FROZEN CLOSE REVISION n (from the close row)
 *     or LIVE / CURRENT (preflight, with its blockers); never summed across
 *     months, currencies or revisions;
 *  5. MRR Snapshot History — the run-rate frozen day by day; not revenue.
 */
#[Title('المالية | سَنَد')]
#[Layout('components.layouts.dashboard')]
class Finance extends Component
{
    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $plan_id = '';

    #[Url]
    public string $provider = '';

    #[Url]
    public string $model = '';

    #[Url]
    public string $operation = '';

    #[Url]
    public string $channel = '';

    #[Url]
    public string $cost = '';

    #[Url]
    public string $attribution = '';

    #[Url]
    public string $granularity = 'day';

    #[Url]
    public int $top = 10;

    public function mount(): void
    {
        abort_unless(auth()->user()?->can(Permission::FinanceView->value) ?? false, 403);

        if ($this->from === '' || $this->to === '') {
            $today = CarbonImmutable::now('UTC');
            $this->to = $today->format('Y-m-d');
            $this->from = $today->subDays(UsageQuery::DEFAULT_DAYS - 1)->format('Y-m-d');
        }
    }

    /**
     * @return array<string, string>
     */
    public function filters(): array
    {
        return [
            'plan_id' => $this->plan_id,
            'provider' => $this->provider,
            'model' => $this->model,
            'operation' => $this->operation,
            'channel' => $this->channel,
            'cost' => $this->cost,
            'attribution' => $this->attribution,
        ];
    }

    public function render(FinanceQuery $finance, MrrCalculator $mrr, MrrSnapshotHistory $history, CashCollectedQuery $cash, ReportingView $reporting, ReconciledMonthSeries $months)
    {
        $user = auth()->user();
        abort_unless($user?->can(Permission::FinanceView->value) ?? false, 403);

        $canExport = (bool) $user->can(Permission::FinanceExport->value);
        $canViewSubscribers = (bool) $user->can(Permission::SubscribersView->value);
        $granularity = in_array($this->granularity, ['day', 'month'], true) ? $this->granularity : 'day';
        $top = max(1, min(FinanceQuery::TOP_LIMIT_MAX, $this->top));

        // Section 1 — as of now; independent of the window and its filters.
        $current = $mrr->current();

        $error = null;
        $window = null;

        try {
            [$from, $to] = UsageQuery::window($this->from, $this->to);
            $query = $finance->build($from, $to, $this->filters());
            $totals = $finance->totals($query);
            $coverage = $finance->coverage($totals);

            $window = [
                'from' => $from->toDateString(),
                'to' => $to->subDay()->toDateString(),
                'totals' => $totals,
                'coverage' => $coverage,
                'margin' => GrossMarginStatus::forWindow($totals, $coverage, $current),
                'byPlan' => $finance->byPlan($query),
                'byProviderModel' => $finance->byProviderModel($query),
                'byOperationChannel' => $finance->byOperationChannel($query),
                'topSubscribers' => $finance->topSubscribers($query, $top),
                'trend' => $trend = $finance->trend($query, $granularity),
                'trendBars' => self::costBars($trend),
                'history' => $series = $history->series($from, $to->subDay()),
                'historyBars' => self::historyBars($series),
                // CASH — live, event-based, per native currency + reporting-currency totals (complete or INCOMPLETE / NOT AVAILABLE).
                'cash' => $cash->summarise($from, $to),
                'reportingCash' => $reporting->cash($from, $to),
                // RECONCILED — per calendar month, one basis each; never a total.
                'months' => $months->forWindow($from, $to),
                'monthKeys' => FrozenCloseReader::monthsCovering($from, $to),
            ];
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        }

        return view('livewire.dashboard.finance', [
            'current' => $current,
            'currentByCurrency' => $current->byCurrency(),
            'unassigned' => $current->unassigned(),
            'window' => $window,
            'error' => $error,
            'granularity' => $granularity,
            'top' => $top,
            'canExport' => $canExport,
            'canViewSubscribers' => $canViewSubscribers,
            'providers' => UsageEvent::query()->whereNotNull('provider')->distinct()->orderBy('provider')->pluck('provider')->all(),
            'plans' => Plan::query()->orderBy('sort_order')->orderBy('id')->get(['id', 'slug']),
            'exportUrl' => $canExport ? route('dashboard.finance.export', array_filter(['from' => $this->from, 'to' => $this->to, 'granularity' => $granularity, 'top' => $top] + $this->filters(), static fn ($v) => $v !== '' && $v !== null)) : null,
            'exports' => $canExport && $window !== null ? [
                'cash' => route('dashboard.finance.cash.export', ['from' => $window['from'], 'to' => $window['to']]),
                'cost' => route('dashboard.finance.cost.export', ['from' => $window['monthKeys'][0], 'to' => $window['monthKeys'][count($window['monthKeys']) - 1]]),
                'fx' => route('dashboard.finance.fx.export', ['from' => $window['from'], 'to' => $window['to']]),
            ] : [],
            'maxDays' => FinanceQuery::MAX_DAYS,
        ]);
    }

    /**
     * Bar widths (0–100, integer) for the cost trend, relative to the largest
     * known cost in the series — integer arithmetic on the scaled amounts.
     *
     * @param  list<CostBucket>  $buckets
     * @return array<string, int>
     */
    public static function costBars(array $buckets): array
    {
        $scaled = [];

        foreach ($buckets as $bucket) {
            $scaled[(string) $bucket->dimensions['bucket']] = DecimalMath::toScaled($bucket->knownCost, 6);
        }

        $max = $scaled === [] ? 0 : max($scaled);

        return array_map(static fn (int $v): int => $max === 0 ? 0 : intdiv($v * 100, $max), $scaled);
    }

    /**
     * Bar widths per captured day and currency for the MRR snapshot history,
     * relative to the largest run-rate of that currency in the window.
     *
     * @return array<string, array<string, int>> date => currency => percent
     */
    public static function historyBars(MrrHistorySeries $series): array
    {
        $scaled = [];
        $max = [];

        foreach ($series->days as $day) {
            foreach ($day->byCurrency as $currency => $entry) {
                $value = DecimalMath::toScaled($entry['mrr'], 6);
                $scaled[$day->date][$currency] = $value;
                $max[$currency] = max($max[$currency] ?? 0, $value);
            }
        }

        $bars = [];

        foreach ($scaled as $date => $byCurrency) {
            foreach ($byCurrency as $currency => $value) {
                $bars[$date][$currency] = $max[$currency] === 0 ? 0 : intdiv($value * 100, $max[$currency]);
            }
        }

        return $bars;
    }
}
