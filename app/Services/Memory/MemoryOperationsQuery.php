<?php

declare(strict_types=1);

namespace App\Services\Memory;

use App\Enums\MemoryCategory;
use App\Enums\MemoryProvenance;
use App\Models\Memory;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * OPERATIONAL METADATA ABOUT DURABLE MEMORY — AND NOTHING ELSE.
 *
 * This service never decrypts, never selects `content`, and never touches
 * `fingerprint`. Not as a policy someone must remember to follow: `SELECTS`
 * below is the column list every query here is restricted to, and a test asserts
 * that neither column appears in any SQL this class produces.
 *
 * Why so strict. `memories.content` is the most personal data Sanad holds, and
 * V1 stores only what a subscriber explicitly asked it to keep — an operator
 * reading it back would be the single most sensitive action the platform could
 * offer, and it is deliberately not built. `memories.fingerprint` is a keyed MAC
 * over that content, so displaying one hands anyone who sees it a confirmation
 * oracle: guess a sentence, compute, compare. Neither belongs on a dashboard.
 *
 * What IS safe, and is what an operator actually needs: how many memories exist,
 * how many are archived, which categories, which provenance, who is at the
 * ceiling, and whether the rows can be opened at all under the current key.
 */
final class MemoryOperationsQuery
{
    /**
     * The only columns any query in this class may read.
     *
     * `content` and `fingerprint` are absent on purpose — see the class docblock.
     *
     * @var list<string>
     */
    public const SELECTS = [
        'id', 'user_id', 'category', 'importance', 'provenance',
        'source_message_id', 'archived_at', 'created_at', 'updated_at',
    ];

    /** @var list<string> */
    public const FILTERS = ['subscriber_id', 'category', 'provenance', 'state', 'importance'];

    /**
     * Platform-wide counts. `memories` is bounded per subscriber by
     * `memory.max_active`, so these grouped aggregates stay proportional to the
     * subscriber count rather than to message volume.
     *
     * @return array{active: int, archived: int, subscribers: int, by_category: array<string, int>, by_provenance: array<string, int>, at_ceiling: int, ceiling: int}
     */
    public static function overview(): array
    {
        $ceiling = max(1, (int) config('memory.max_active', 50));

        $byCategory = [];

        foreach (Memory::query()->whereNull('archived_at')->selectRaw('category, count(*) as n')->groupBy('category')->get() as $row) {
            $byCategory[self::plain($row->getAttribute('category'))] = (int) $row->getAttribute('n');
        }

        $byProvenance = [];

        foreach (Memory::query()->whereNull('archived_at')->selectRaw('provenance, count(*) as n')->groupBy('provenance')->get() as $row) {
            $byProvenance[self::plain($row->getAttribute('provenance'))] = (int) $row->getAttribute('n');
        }

        ksort($byCategory);
        ksort($byProvenance);

        $atCeiling = Memory::query()
            ->whereNull('archived_at')
            ->groupBy('user_id')
            ->havingRaw('count(*) >= ?', [$ceiling])
            ->get(['user_id'])
            ->count();

        return [
            'active' => Memory::query()->whereNull('archived_at')->count(),
            'archived' => Memory::query()->whereNotNull('archived_at')->count(),
            'subscribers' => Memory::query()->distinct()->count('user_id'),
            'by_category' => $byCategory,
            'by_provenance' => $byProvenance,
            'at_ceiling' => $atCeiling,
            'ceiling' => $ceiling,
        ];
    }

    /**
     * Subscribers who hold memories, with their counts — the list an operator
     * drills from. One grouped query, paginated.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, \stdClass>
     */
    public static function subscribers(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $subscriber = trim((string) ($filters['subscriber_id'] ?? ''));

        return Memory::query()
            ->selectRaw('user_id')
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when archived_at is null then 1 else 0 end) as active')
            ->selectRaw('sum(case when archived_at is not null then 1 else 0 end) as archived')
            ->when($subscriber !== '' && ctype_digit($subscriber), static fn (Builder $q) => $q->where('user_id', (int) $subscriber))
            ->groupBy('user_id')
            ->orderByRaw('sum(case when archived_at is null then 1 else 0 end) desc')
            ->orderBy('user_id')
            ->paginate($perPage);
    }

    /**
     * One subscriber's memory rows as METADATA ONLY — the select list cannot
     * reach `content` or `fingerprint`.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Memory>
     */
    public static function rowsFor(User $subscriber, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $value = static fn (string $key): string => trim((string) ($filters[$key] ?? ''));

        $category = $value('category');
        $provenance = $value('provenance');
        $state = $value('state');
        $importance = $value('importance');

        return Memory::query()
            ->select(self::SELECTS)
            ->where('user_id', $subscriber->getKey())
            ->when($category !== '' && MemoryCategory::tryFrom($category) !== null, static fn (Builder $q) => $q->where('category', $category))
            ->when($provenance !== '' && MemoryProvenance::tryFrom($provenance) !== null, static fn (Builder $q) => $q->where('provenance', $provenance))
            ->when($state === 'active', static fn (Builder $q) => $q->whereNull('archived_at'))
            ->when($state === 'archived', static fn (Builder $q) => $q->whereNotNull('archived_at'))
            ->when($importance !== '' && ctype_digit($importance), static fn (Builder $q) => $q->where('importance', (int) $importance))
            ->orderByDesc('importance')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Per-subscriber counts for the detail header.
     *
     * @return array{active: int, archived: int, ceiling: int, at_ceiling: bool, by_category: array<string, int>, by_provenance: array<string, int>}
     */
    public static function summaryFor(User $subscriber): array
    {
        $ceiling = max(1, (int) config('memory.max_active', 50));
        $active = Memory::query()->where('user_id', $subscriber->getKey())->whereNull('archived_at')->count();

        $byCategory = [];

        foreach (
            Memory::query()->where('user_id', $subscriber->getKey())->whereNull('archived_at')
                ->selectRaw('category, count(*) as n')->groupBy('category')->get() as $row
        ) {
            $byCategory[self::plain($row->getAttribute('category'))] = (int) $row->getAttribute('n');
        }

        $byProvenance = [];

        foreach (
            Memory::query()->where('user_id', $subscriber->getKey())->whereNull('archived_at')
                ->selectRaw('provenance, count(*) as n')->groupBy('provenance')->get() as $row
        ) {
            $byProvenance[self::plain($row->getAttribute('provenance'))] = (int) $row->getAttribute('n');
        }

        ksort($byCategory);
        ksort($byProvenance);

        return [
            'active' => $active,
            'archived' => Memory::query()->where('user_id', $subscriber->getKey())->whereNotNull('archived_at')->count(),
            'ceiling' => $ceiling,
            'at_ceiling' => $active >= $ceiling,
            'by_category' => $byCategory,
            'by_provenance' => $byProvenance,
        ];
    }

    private static function plain(mixed $value): string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : (string) $value;
    }
}
