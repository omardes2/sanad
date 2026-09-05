<?php

declare(strict_types=1);

namespace App\Services\Usage;

use App\Enums\CostSource;
use App\Models\UsageEvent;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streaming CSV export of the usage ledger (Phase C2, decision 8).
 *
 *  - behind `usage.export` (super_admin + finance) — enforced by the route AND
 *    the controller; cost columns additionally require `usage.view_costs`;
 *  - an explicit, bounded date window is REQUIRED (UsageQuery);
 *  - streamed in chunks (lazyById) — never materialised in memory;
 *  - NO personal data: no message content, no names, no emails, no phone
 *    numbers, no metadata; the subscriber appears as its internal id only.
 */
final class UsageExporter
{
    public const CHUNK = 1000;

    /** @var list<string> */
    private const BASE_COLUMNS = [
        'id', 'occurred_at', 'subscriber_id', 'subscription_id', 'plan_slug', 'type', 'operation', 'channel',
        'outcome', 'provider', 'model', 'ai_model_id', 'input_units', 'output_units', 'cached_units',
        'quantity', 'duration_ms', 'cost_source', 'cost_known',
    ];

    /** @var list<string> */
    private const COST_COLUMNS = ['model_price_id', 'provider_cost', 'communication_cost', 'external_cost', 'total_cost', 'currency'];

    /**
     * @return list<string>
     */
    public static function columns(bool $includeCosts): array
    {
        return $includeCosts ? [...self::BASE_COLUMNS, ...self::COST_COLUMNS] : self::BASE_COLUMNS;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function stream(CarbonImmutable $from, CarbonImmutable $to, array $filters, bool $includeCosts): StreamedResponse
    {
        $query = UsageQuery::build($from, $to, $filters);
        $columns = self::columns($includeCosts);
        $filename = sprintf('sanad-usage-%s-%s.csv', $from->format('Ymd'), $to->subDay()->format('Ymd'));

        return response()->streamDownload(function () use ($query, $columns): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for spreadsheet apps
            fputcsv($out, $columns);

            foreach ($query->select($this->selectFor($columns))->lazyById(self::CHUNK) as $event) {
                fputcsv($out, $this->row($event, $columns));

                if (function_exists('flush')) {
                    flush();
                }
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    private function selectFor(array $columns): array
    {
        return array_values(array_unique([...array_diff($columns, ['cost_known']), 'id']));
    }

    /**
     * @param  list<string>  $columns
     * @return list<string|int|null>
     */
    private function row(UsageEvent $event, array $columns): array
    {
        $row = [];

        foreach ($columns as $column) {
            $row[] = match ($column) {
                'occurred_at' => $event->occurred_at?->toIso8601String(),
                'outcome' => $event->outcome?->value,
                'cost_source' => $event->cost_source instanceof CostSource ? $event->cost_source->value : null,
                'cost_known' => $event->hasKnownCost() ? 'yes' : 'no',
                default => $this->scalar($event->getAttribute($column)),
            };
        }

        return $row;
    }

    private function scalar(mixed $value): string|int|null
    {
        return match (true) {
            $value === null => null,
            is_int($value) => $value,
            is_bool($value) => $value ? 1 : 0,
            is_scalar($value) => (string) $value,
            default => null, // arrays/objects (metadata) are never exported
        };
    }
}
