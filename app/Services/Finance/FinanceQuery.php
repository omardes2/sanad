<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Data\Finance\CostBucket;
use App\Data\Finance\CostCoverage;
use App\Data\Finance\CostTotals;
use App\Enums\CostSource;
use App\Enums\CoverageStatus;
use App\Models\UsageEvent;
use App\Services\Usage\UsageQuery;
use App\Support\Billing\DecimalMath;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The one place that turns finance filters into ledger aggregations (Phase D1),
 * shared by the Finance page and its CSV export (Phase D2) so they can never
 * disagree.
 *
 * Contract (Calculated, never Collected/Actual/Reconciled):
 *  - money comes from PRICED rows only, summed as scaled integers in SQL and
 *    formatted by DecimalMath — no PHP floats anywhere;
 *  - UNPRICED rows (none / currency_mismatch / NULL) are counted, never summed;
 *  - system-attributed rows (subscriber_id NULL, e.g. billable health checks)
 *    are real platform cost, reported in their own bucket and excluded from
 *    every per-subscriber / per-plan figure;
 *  - day/month buckets are UTC calendar buckets (occurred_at is stored in UTC).
 */
final class FinanceQuery
{
    /** Longest window for row-level breakdowns (same bound as the usage browser). */
    public const MAX_DAYS = UsageQuery::MAX_DAYS;

    /** Longest window for the monthly trend (aggregated only). */
    public const MAX_TREND_DAYS = 366;

    public const TOP_LIMIT_MAX = 50;

    /** @var list<string> */
    public const FILTERS = [...UsageQuery::FILTERS, 'plan_id', 'channel', 'attribution'];

    public const PLAN_NONE = 'none';

    public function __construct(private readonly FinanceSql $sql) {}

    /**
     * @param  array<string, mixed>  $filters  UsageQuery filters plus plan_id (id | "none"), channel, attribution ("subscriber" | "system")
     * @return Builder<UsageEvent>
     *
     * @throws InvalidArgumentException when the window is missing, reversed or too long
     */
    public function build(CarbonImmutable $from, CarbonImmutable $to, array $filters = [], int $maxDays = self::MAX_DAYS): Builder
    {
        $query = UsageQuery::build($from, $to, $filters, $maxDays);

        $plan = trim((string) ($filters['plan_id'] ?? ''));
        $channel = trim((string) ($filters['channel'] ?? ''));
        $attribution = trim((string) ($filters['attribution'] ?? ''));

        return $query
            ->when($plan === self::PLAN_NONE, static fn (Builder $q) => $q->whereNull('plan_id'))
            ->when($plan !== '' && ctype_digit($plan), static fn (Builder $q) => $q->where('plan_id', (int) $plan))
            ->when($channel !== '', static fn (Builder $q) => $q->where('channel', $channel))
            ->when($attribution === 'system', static fn (Builder $q) => $q->whereNull('subscriber_id'))
            ->when($attribution === 'subscriber', static fn (Builder $q) => $q->whereNotNull('subscriber_id'));
    }

    /**
     * @param  Builder<UsageEvent>  $query
     */
    public function totals(Builder $query): CostTotals
    {
        $priced = $this->sql->pricedPredicate();
        $unpriced = $this->sql->unpricedPredicate();

        $row = (clone $query)
            ->toBase()
            ->selectRaw(implode(', ', [
                'COUNT(*) AS rows_count',
                $this->sql->countWhere($priced).' AS priced_rows',
                $this->sql->countWhere($unpriced).' AS unpriced_rows',
                $this->sql->scaledSum('provider_cost', $priced).' AS provider_scaled',
                $this->sql->scaledSum('communication_cost', $priced).' AS communication_scaled',
                $this->sql->scaledSum('external_cost', $priced).' AS external_scaled',
                $this->sql->scaledSum('total_cost', $priced).' AS total_scaled',
                'COALESCE(SUM(input_units), 0) AS input_units',
                'COALESCE(SUM(output_units), 0) AS output_units',
                'COALESCE(SUM(cached_units), 0) AS cached_units',
                $this->sql->sumWhere('input_units', $unpriced).' AS unpriced_input_units',
                $this->sql->sumWhere('output_units', $unpriced).' AS unpriced_output_units',
                $this->sql->countWhere('subscriber_id IS NULL').' AS system_rows',
                $this->sql->countWhere("channel = 'whatsapp'").' AS whatsapp_rows',
                $this->sql->countWhere('channel IS NULL').' AS unknown_channel_rows',
            ]))
            ->first();

        $byReason = [];

        foreach ((clone $query)->unpriced()->toBase()->selectRaw('cost_source, COUNT(*) AS n')->groupBy('cost_source')->get() as $reason) {
            $key = $reason->cost_source === null ? 'legacy' : (string) $reason->cost_source;
            $byReason[$key] = DecimalMath::intFromDb($reason->n);
        }

        ksort($byReason);

        return new CostTotals(
            currency: (string) config('billing.cost_currency', 'USD'),
            rows: DecimalMath::intFromDb($row->rows_count),
            pricedRows: DecimalMath::intFromDb($row->priced_rows),
            unpricedRows: DecimalMath::intFromDb($row->unpriced_rows),
            unpricedByReason: $byReason,
            knownProviderCost: $this->money($row->provider_scaled),
            knownCommunicationCost: $this->money($row->communication_scaled),
            knownExternalCost: $this->money($row->external_scaled),
            knownTotalCost: $this->money($row->total_scaled),
            inputUnits: DecimalMath::intFromDb($row->input_units),
            outputUnits: DecimalMath::intFromDb($row->output_units),
            cachedUnits: DecimalMath::intFromDb($row->cached_units),
            unpricedInputUnits: DecimalMath::intFromDb($row->unpriced_input_units),
            unpricedOutputUnits: DecimalMath::intFromDb($row->unpriced_output_units),
            systemRows: DecimalMath::intFromDb($row->system_rows),
            whatsappChannelRows: DecimalMath::intFromDb($row->whatsapp_rows),
            unknownChannelRows: DecimalMath::intFromDb($row->unknown_channel_rows),
        );
    }

    /**
     * Coverage of the window's known cost, per component:
     *  - provider: incomplete as soon as one row is unpriced;
     *  - communication: no producer exists (CostProducers); the moment the
     *    window holds WhatsApp-channel usage — or legacy rows whose channel is
     *    unknown — communication cost is missing, hence INCOMPLETE;
     *  - external: no producer — never reported as zero.
     */
    public function coverage(CostTotals $totals): CostCoverage
    {
        $provider = $totals->unpricedRows > 0 ? CoverageStatus::Incomplete : CoverageStatus::Complete;

        $communicationUncovered = $totals->whatsappChannelRows + $totals->unknownChannelRows;
        $communication = match (true) {
            CostProducers::COMMUNICATION => CoverageStatus::Complete,
            $communicationUncovered > 0 => CoverageStatus::Incomplete,
            default => CoverageStatus::NoProducer,
        };

        $external = CostProducers::EXTERNAL ? CoverageStatus::Complete : CoverageStatus::NoProducer;

        return new CostCoverage(
            provider: $provider,
            providerUnpricedRows: $totals->unpricedRows,
            communication: $communication,
            communicationUncoveredRows: $communicationUncovered,
            external: $external,
        );
    }

    /**
     * Cost per plan snapshot. System rows form their own bucket
     * (attribution=system); subscriber rows without a plan form the "none" bucket.
     *
     * @param  Builder<UsageEvent>  $query
     * @return list<CostBucket>
     */
    public function byPlan(Builder $query): array
    {
        $isSystem = 'CASE WHEN subscriber_id IS NULL THEN 1 ELSE 0 END';

        return $this->buckets(
            $query,
            ["{$isSystem} AS is_system", 'plan_id', 'plan_slug'],
            [$isSystem, 'plan_id', 'plan_slug'],
            static fn (object $row): array => [
                'attribution' => DecimalMath::intFromDb($row->is_system) === 1 ? 'system' : 'subscriber',
                'plan_id' => $row->plan_id === null ? null : DecimalMath::intFromDb($row->plan_id),
                'plan_slug' => $row->plan_slug,
            ],
        );
    }

    /**
     * @param  Builder<UsageEvent>  $query
     * @return list<CostBucket>
     */
    public function byProviderModel(Builder $query): array
    {
        return $this->buckets(
            $query,
            ['provider', 'model'],
            ['provider', 'model'],
            static fn (object $row): array => ['provider' => $row->provider, 'model' => $row->model],
        );
    }

    /**
     * @param  Builder<UsageEvent>  $query
     * @return list<CostBucket>
     */
    public function byOperationChannel(Builder $query): array
    {
        return $this->buckets(
            $query,
            ['operation', 'channel'],
            ['operation', 'channel'],
            static fn (object $row): array => ['operation' => $row->operation, 'channel' => $row->channel],
        );
    }

    /**
     * Highest known-cost subscribers (system rows excluded), ties broken by
     * unpriced rows then id so the order is deterministic on both engines.
     * Only the pseudonymous subscriber id is returned — never a name or phone.
     *
     * @param  Builder<UsageEvent>  $query
     * @return list<CostBucket>
     */
    public function topSubscribers(Builder $query, int $limit = 10): array
    {
        $limit = max(1, min(self::TOP_LIMIT_MAX, $limit));

        return $this->buckets(
            (clone $query)->whereNotNull('subscriber_id'),
            ['subscriber_id'],
            ['subscriber_id'],
            static fn (object $row): array => ['subscriber_id' => DecimalMath::intFromDb($row->subscriber_id)],
            orderBy: 'known_scaled DESC, unpriced_rows DESC, subscriber_id ASC',
            limit: $limit,
        );
    }

    /**
     * Known cost and unpriced rows per UTC day or month, oldest first. Buckets
     * without rows are absent (the caller decides how to render gaps).
     *
     * @param  Builder<UsageEvent>  $query
     * @return list<CostBucket>
     */
    public function trend(Builder $query, string $granularity = 'day'): array
    {
        $bucket = $this->sql->dateBucket('occurred_at', $granularity);

        return $this->buckets(
            $query,
            ["{$bucket} AS bucket"],
            [$bucket],
            static fn (object $row): array => ['bucket' => (string) $row->bucket],
            orderBy: 'bucket ASC',
        );
    }

    /**
     * @param  Builder<UsageEvent>  $query
     * @param  list<string>  $selects  raw select fragments for the dimensions
     * @param  list<string>  $groupBy  raw group-by expressions
     * @param  callable(object): array<string, int|string|null>  $dimensions
     * @return list<CostBucket>
     */
    private function buckets(Builder $query, array $selects, array $groupBy, callable $dimensions, ?string $orderBy = null, ?int $limit = null): array
    {
        $priced = $this->sql->pricedPredicate();
        $unpriced = $this->sql->unpricedPredicate();

        $base = (clone $query)
            ->toBase()
            ->selectRaw(implode(', ', [
                ...$selects,
                'COUNT(*) AS rows_count',
                $this->sql->countWhere($priced).' AS priced_rows',
                $this->sql->countWhere($unpriced).' AS unpriced_rows',
                $this->sql->scaledSum('total_cost', $priced).' AS known_scaled',
                $this->sql->scaledSum('provider_cost', $priced).' AS provider_scaled',
                $this->sql->scaledSum('communication_cost', $priced).' AS communication_scaled',
                'COALESCE(SUM(input_units), 0) AS input_units',
                'COALESCE(SUM(output_units), 0) AS output_units',
            ]))
            ->groupBy(array_map(static fn (string $expr) => DB::raw($expr), $groupBy))
            ->orderByRaw($orderBy ?? 'known_scaled DESC, rows_count DESC');

        if ($limit !== null) {
            $base->limit($limit);
        }

        $buckets = [];

        foreach ($base->get() as $row) {
            $buckets[] = new CostBucket(
                dimensions: $dimensions($row),
                rows: DecimalMath::intFromDb($row->rows_count),
                pricedRows: DecimalMath::intFromDb($row->priced_rows),
                unpricedRows: DecimalMath::intFromDb($row->unpriced_rows),
                knownCost: $this->money($row->known_scaled),
                knownProviderCost: $this->money($row->provider_scaled),
                knownCommunicationCost: $this->money($row->communication_scaled),
                inputUnits: DecimalMath::intFromDb($row->input_units),
                outputUnits: DecimalMath::intFromDb($row->output_units),
            );
        }

        return $buckets;
    }

    private function money(mixed $scaled): string
    {
        return DecimalMath::format(DecimalMath::intFromDb($scaled), FinanceSql::LEDGER_SCALE);
    }

    public static function reasonLabel(string $reason): string
    {
        return match ($reason) {
            'legacy' => 'صفوف سابقة لدفتر الأسعار (بلا مصدر تكلفة)',
            default => CostSource::tryFrom($reason)?->label() ?? $reason,
        };
    }
}
