<?php

declare(strict_types=1);

namespace App\Services\Tools\Readers;

use App\Models\Memory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The real domain reader behind `memory.read@1` (Phase F2).
 *
 * It is a READ: one bounded SELECT against the subscriber's own memories, no
 * write of any kind, and no call to a model or a provider.
 *
 * OWNERSHIP IS NOT AN ARGUMENT. The subscriber is passed in from the
 * invocation's trusted context (the owner of the stored message); the tool
 * schema of `memory.read@1` declares only `query` and `limit`, so there is no
 * field a model could use to name another subscriber, another conversation, a
 * capability or a permission — and `ToolSchema::validate()` refuses any field
 * it does not declare, so one cannot be smuggled in either.
 *
 * The result is deterministic and structured — how many active memories match
 * and whether the bound cut the answer short — and it is validated against the
 * tool's declared OUTPUT schema before it is stored or returned. It carries no
 * memory content, which is exactly why an operator reading the audit trail
 * later cannot read a subscriber's memories through it.
 */
final class MemoryReader
{
    /** The declared bound of `limit` in `memory.read@1`; the schema also caps it. */
    public const MAX_LIMIT = 50;

    public const DEFAULT_LIMIT = 10;

    /**
     * @param  array{query: string, limit?: int}  $input  already validated and canonicalised
     * @return array{matches: int, truncated: bool}
     */
    public function read(User $subscriber, array $input): array
    {
        $limit = max(1, min((int) ($input['limit'] ?? self::DEFAULT_LIMIT), self::MAX_LIMIT));

        // One bounded read of ONE subscriber's active memories. `limit + 1` rows
        // is how "there was more" is known without counting the whole table.
        $found = Memory::query()
            ->where('user_id', $subscriber->getKey())
            ->whereNull('archived_at')
            ->whereRaw(self::matchExpression(), ['%'.self::escapeLike($input['query']).'%'])
            ->orderBy('id')
            ->limit($limit + 1)
            ->count();

        return [
            'matches' => min($found, $limit),
            'truncated' => $found > $limit,
        ];
    }

    /**
     * Case-insensitive on BOTH drivers, so the same memories and the same query
     * always give the same answer: SQLite's LIKE already ignores ASCII case,
     * PostgreSQL's does not and needs ILIKE. The explicit ESCAPE clause is what
     * makes the escaping below mean the same thing on both.
     *
     * The fragment is a fixed string chosen by driver — no caller value ever
     * reaches it; the pattern itself is always a bound parameter.
     */
    private static function matchExpression(): string
    {
        return DB::getDriverName() === 'pgsql'
            ? "content ILIKE ? ESCAPE '\\'"
            : "content LIKE ? ESCAPE '\\'";
    }

    /** The query is a literal substring, never a pattern the caller controls. */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
