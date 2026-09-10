<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Enums\ToolCapability;
use App\Enums\ToolConsentStatus;
use App\Models\ToolConsent;
use Illuminate\Database\Eloquent\Builder;

/**
 * Admin reads over `tool_consents`.
 *
 * No window is required here, and that is deliberate rather than an oversight:
 * `tool_consents` holds at most one row per subscriber per capability — four rows
 * per subscriber, current state only — so the table is bounded by the subscriber
 * count and a window would hide exactly the rows an operator needs (a consent
 * granted a year ago is the most relevant kind). The `(capability, status)` index
 * already exists for precisely this query.
 *
 * History is not here. `tool_consents` keeps current state plus a `version`; the
 * transitions live in `audit_logs`, which the detail page links into rather than
 * duplicating.
 */
final class ToolConsentQuery
{
    /** @var list<string> */
    public const FILTERS = ['capability', 'status', 'subscriber_id'];

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<ToolConsent>
     */
    public static function build(array $filters = []): Builder
    {
        $value = static fn (string $key): string => trim((string) ($filters[$key] ?? ''));

        $capability = $value('capability');
        $status = $value('status');
        $subscriber = $value('subscriber_id');

        return ToolConsent::query()
            ->when($capability !== '' && ToolCapability::tryFrom($capability) !== null, static fn (Builder $q) => $q->where('capability', $capability))
            ->when($status !== '' && ToolConsentStatus::tryFrom($status) !== null, static fn (Builder $q) => $q->where('status', $status))
            ->when($subscriber !== '' && ctype_digit($subscriber), static fn (Builder $q) => $q->where('subscriber_id', (int) $subscriber));
    }

    /**
     * One grouped query per axis, not one count per cell.
     *
     * @return array{total: int, by_capability: array<string, array<string, int>>}
     */
    public static function totals(?Builder $query = null): array
    {
        $query ??= ToolConsent::query();
        $matrix = [];
        $total = 0;

        foreach ((clone $query)->selectRaw('capability, status, count(*) as n')->groupBy('capability', 'status')->get() as $row) {
            // Both columns are CAST on the model, so a grouped row hands back
            // enum instances, not strings — casting one to string directly is a
            // fatal, and it is the kind that only shows up once a row exists.
            $capability = $row->getAttribute('capability');
            $capability = $capability instanceof ToolCapability ? $capability->value : (string) $capability;
            $status = $row->getAttribute('status');
            $status = $status instanceof ToolConsentStatus ? $status->value : (string) $status;
            $n = (int) $row->getAttribute('n');

            $matrix[$capability][$status] = $n;
            $total += $n;
        }

        ksort($matrix);

        return ['total' => $total, 'by_capability' => $matrix];
    }
}
