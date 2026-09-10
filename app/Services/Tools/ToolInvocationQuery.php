<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Enums\ToolCapability;
use App\Enums\ToolInvocationFailureKind;
use App\Enums\ToolInvocationRefusalReason;
use App\Enums\ToolInvocationStatus;
use App\Enums\ToolSideEffect;
use App\Models\ToolInvocation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * The one place that turns admin filters into a `tool_invocations` query, in the
 * shape `UsageQuery` (Phase C2) already proved: a REQUIRED, BOUNDED window and a
 * closed filter list.
 *
 * `tool_invocations` is on course to be the largest table in the platform — one
 * row per tool call per message, forever. An admin page that let a component
 * assemble ad-hoc queries over it would eventually take production down from a
 * dropdown. So every filter here is either an indexed column or an exact match
 * on a unique key, and the window is not optional.
 *
 * The two indexes this leans on already exist (`(subscriber_id, created_at)` and
 * `(status, created_at)`), which is why this phase needs no migration.
 *
 * IDENTITY SEARCH, not content search: `idempotency_key` and `input_hash` are
 * exact-match lookups. There is no free-text search over inputs, because raw
 * arguments are never stored in the first place.
 */
final class ToolInvocationQuery
{
    public const MAX_DAYS = 92;

    public const DEFAULT_DAYS = 7;

    /** @var list<string> */
    public const FILTERS = [
        'subscriber_id', 'tool_key', 'capability', 'side_effect',
        'status', 'failure_kind', 'refusal_reason', 'idempotency_key', 'input_hash',
    ];

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<ToolInvocation>
     *
     * @throws InvalidArgumentException when the window is missing, reversed or too long
     */
    public static function build(CarbonImmutable $from, CarbonImmutable $to, array $filters = [], int $maxDays = self::MAX_DAYS): Builder
    {
        self::assertWindow($from, $to, $maxDays);

        $value = static fn (string $key): string => trim((string) ($filters[$key] ?? ''));

        $subscriber = $value('subscriber_id');
        $toolKey = $value('tool_key');
        $capability = $value('capability');
        $sideEffect = $value('side_effect');
        $status = $value('status');
        $failureKind = $value('failure_kind');
        $refusalReason = $value('refusal_reason');
        $idempotencyKey = $value('idempotency_key');
        $inputHash = $value('input_hash');

        return ToolInvocation::query()
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->when($subscriber !== '' && ctype_digit($subscriber), static fn (Builder $q) => $q->where('subscriber_id', (int) $subscriber))
            ->when($toolKey !== '', static fn (Builder $q) => self::whereToolKey($q, $toolKey))
            ->when($capability !== '' && ToolCapability::tryFrom($capability) !== null, static fn (Builder $q) => $q->where('capability', $capability))
            ->when($sideEffect !== '' && ToolSideEffect::tryFrom($sideEffect) !== null, static fn (Builder $q) => $q->where('side_effect', $sideEffect))
            ->when($status !== '' && ToolInvocationStatus::tryFrom($status) !== null, static fn (Builder $q) => $q->where('status', $status))
            ->when($failureKind !== '' && ToolInvocationFailureKind::tryFrom($failureKind) !== null, static fn (Builder $q) => $q->where('failure_kind', $failureKind))
            ->when($refusalReason !== '' && ToolInvocationRefusalReason::tryFrom($refusalReason) !== null, static fn (Builder $q) => $q->where('refusal_reason', $refusalReason))
            ->when($idempotencyKey !== '', static fn (Builder $q) => $q->where('idempotency_key', $idempotencyKey))
            ->when($inputHash !== '', static fn (Builder $q) => $q->where('input_hash', $inputHash));
    }

    /**
     * Counts per terminal state over the window — the breakdown the operator
     * actually asks for, in ONE grouped query rather than seven counts.
     *
     * @param  Builder<ToolInvocation>  $query
     * @return array{total: int, by_status: array<string, int>, by_failure_kind: array<string, int>, by_refusal_reason: array<string, int>}
     */
    public static function totals(Builder $query): array
    {
        $byStatus = self::group($query, 'status');
        $byFailure = self::group((clone $query)->whereNotNull('failure_kind'), 'failure_kind');
        $byRefusal = self::group((clone $query)->whereNotNull('refusal_reason'), 'refusal_reason');

        return [
            'total' => array_sum($byStatus),
            'by_status' => $byStatus,
            'by_failure_kind' => $byFailure,
            'by_refusal_reason' => $byRefusal,
        ];
    }

    /**
     * `memory.read@2` in the UI is `tool_key = memory.read` AND `tool_version = 2`
     * on the row — the version is a separate column on purpose, so the contract
     * a call was claimed against is never ambiguous.
     *
     * @param  Builder<ToolInvocation>  $query
     * @return Builder<ToolInvocation>
     */
    private static function whereToolKey(Builder $query, string $toolKey): Builder
    {
        if (preg_match('/^(?<name>[a-z][a-z0-9._-]*)@(?<version>\d+)$/', $toolKey, $matches) === 1) {
            return $query->where('tool_key', $matches['name'])->where('tool_version', (int) $matches['version']);
        }

        return $query->where('tool_key', $toolKey);
    }

    /**
     * @param  Builder<ToolInvocation>  $query
     * @return array<string, int>
     */
    private static function group(Builder $query, string $column): array
    {
        $counts = [];

        foreach ((clone $query)->selectRaw($column.', count(*) as n')->groupBy($column)->get() as $row) {
            $value = $row->getAttribute($column);

            if ($value === null) {
                continue;
            }

            $key = $value instanceof \BackedEnum ? (string) $value->value : (string) $value;
            $counts[$key] = (int) $row->getAttribute('n');
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function assertWindow(CarbonImmutable $from, CarbonImmutable $to, int $maxDays = self::MAX_DAYS): void
    {
        if ($to <= $from) {
            throw new InvalidArgumentException('نهاية النطاق يجب أن تكون بعد بدايته.');
        }

        if ($from->diffInDays($to) > $maxDays) {
            throw new InvalidArgumentException('النطاق الأقصى '.$maxDays.' يومًا.');
        }
    }

    /**
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
        $end = CarbonImmutable::createFromFormat('Y-m-d', $to)->startOfDay()->addDay();

        self::assertWindow($start, $end);

        return [$start, $end];
    }
}
