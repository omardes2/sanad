<?php

declare(strict_types=1);

namespace App\Services\Usage;

use App\Enums\CostSource;
use App\Models\UsageEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * The one place that turns admin filters into a ledger query (Phase C2), so
 * the Usage page and the CSV export can never disagree about which rows a
 * window covers. A window is REQUIRED and bounded: exports and browsing are
 * always explicit about their date range.
 *
 * Totals follow the ledger's contract: only PRICED rows are summed as money;
 * UNPRICED rows (cost_source none / currency_mismatch / NULL for pre-ledger
 * rows) are COUNTED, never summed — their zero is not "free".
 */
final class UsageQuery
{
    public const MAX_DAYS = 92;

    public const DEFAULT_DAYS = 30;

    /** @var list<string> */
    public const FILTERS = ['provider', 'model', 'subscriber_id', 'outcome', 'operation', 'cost'];

    /**
     * @param  array<string, mixed>  $filters  provider, model, subscriber_id, outcome, operation, cost (priced|unpriced)
     * @return Builder<UsageEvent>
     *
     * @throws InvalidArgumentException when the window is missing, reversed or too long
     */
    public static function build(CarbonImmutable $from, CarbonImmutable $to, array $filters = []): Builder
    {
        self::assertWindow($from, $to);

        $query = UsageEvent::query()
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<', $to);

        $provider = trim((string) ($filters['provider'] ?? ''));
        $model = trim((string) ($filters['model'] ?? ''));
        $subscriber = trim((string) ($filters['subscriber_id'] ?? ''));
        $outcome = trim((string) ($filters['outcome'] ?? ''));
        $operation = trim((string) ($filters['operation'] ?? ''));
        $cost = trim((string) ($filters['cost'] ?? ''));

        return $query
            ->when($provider !== '', static fn (Builder $q) => $q->where('provider', $provider))
            ->when($model !== '', static fn (Builder $q) => $q->where('model', $model))
            ->when($subscriber !== '' && ctype_digit($subscriber), static fn (Builder $q) => $q->where('subscriber_id', (int) $subscriber))
            ->when($outcome !== '', static fn (Builder $q) => $q->where('outcome', $outcome))
            ->when($operation !== '', static fn (Builder $q) => $q->where('operation', $operation))
            ->when($cost === 'priced', static fn (Builder $q) => $q->priced())
            ->when($cost === 'unpriced', static fn (Builder $q) => $q->unpriced());
    }

    /**
     * Money only from priced rows; unpriced rows counted by reason.
     *
     * @param  Builder<UsageEvent>  $query
     * @return array{rows: int, priced_rows: int, priced_total: string, currency: string, unpriced_rows: int, unpriced_by_reason: array<string, int>, input_units: int, output_units: int}
     */
    public static function totals(Builder $query): array
    {
        $priced = (clone $query)->priced();
        $unpriced = (clone $query)->unpriced();

        $byReason = [];

        foreach ((clone $unpriced)->selectRaw('cost_source, count(*) as n')->groupBy('cost_source')->get() as $row) {
            $source = $row->getAttribute('cost_source');
            $key = $source instanceof CostSource ? $source->value : ($source === null ? 'legacy' : (string) $source);
            $byReason[$key] = (int) $row->getAttribute('n');
        }

        ksort($byReason);

        return [
            'rows' => (clone $query)->count(),
            'priced_rows' => (clone $priced)->count(),
            'priced_total' => number_format((float) ((clone $priced)->sum('total_cost') ?? 0), 6, '.', ''),
            'currency' => (string) config('billing.cost_currency', 'USD'),
            'unpriced_rows' => (clone $unpriced)->count(),
            'unpriced_by_reason' => $byReason,
            'input_units' => (int) (clone $query)->sum('input_units'),
            'output_units' => (int) (clone $query)->sum('output_units'),
        ];
    }

    public static function reasonLabel(string $reason): string
    {
        return match ($reason) {
            'legacy' => 'صفوف سابقة لدفتر الأسعار (بلا مصدر تكلفة)',
            default => CostSource::tryFrom($reason)?->label() ?? $reason,
        };
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function assertWindow(CarbonImmutable $from, CarbonImmutable $to): void
    {
        if ($to <= $from) {
            throw new InvalidArgumentException('نهاية النطاق يجب أن تكون بعد بدايته.');
        }

        if ($from->diffInDays($to) > self::MAX_DAYS) {
            throw new InvalidArgumentException('النطاق الأقصى '.self::MAX_DAYS.' يومًا.');
        }
    }

    /**
     * Parse "Y-m-d" bounds into a half-open [from, to) window in the app timezone.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     *
     * @throws InvalidArgumentException
     */
    public static function window(string $from, string $to): array
    {
        foreach (['from' => $from, 'to' => $to] as $name => $value) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
                throw new InvalidArgumentException("التاريخ [{$name}] مطلوب بصيغة YYYY-MM-DD.");
            }
        }

        $start = CarbonImmutable::createFromFormat('Y-m-d', $from)->startOfDay();
        $end = CarbonImmutable::createFromFormat('Y-m-d', $to)->startOfDay()->addDay(); // inclusive end date

        self::assertWindow($start, $end);

        return [$start, $end];
    }
}
