<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Enums\ToolInvocationStatus;
use App\Enums\ToolSideEffect;
use App\Exceptions\Tools\ToolTransitionException;
use App\Models\ToolInvocation;
use App\Support\Tools\ToolRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Recovery for READ invocations left `running` by a process that died (Phase F2).
 *
 * This is NOT a retry mechanism and must never become one. It never runs a
 * tool, never marks anything `succeeded`, never manufactures an output and
 * never touches an invocation whose side-effect class is not `read`. Its only
 * power is to say "this synchronous read cannot still be running" and settle it
 * as `timed_out`, once.
 *
 * A candidate must satisfy ALL of:
 *   - status is `running` and side effect is `read`;
 *   - the tool VERSION still exists in the code registry — the expiry is
 *     recomputed from that immutable definition, never guessed;
 *   - `started_at + timeout_ms + grace` is already in the past.
 *
 * Each candidate is then locked `FOR UPDATE` and re-read: if it is no longer
 * `running`, the original worker settled it first and the sweeper leaves it
 * alone. If both reach the transition, the code transition table refuses the
 * loser, so there is exactly one terminal status, one terminal event, one
 * audit entry and one usage row — never a success AND a timeout.
 */
final class StaleInvocationSweeper
{
    /** The default cushion on top of the tool version's own timeout. */
    public const GRACE_SECONDS = 30;

    /** A bounded batch: recovery never turns into a table scan. */
    public const MAX_BATCH = 200;

    public function __construct(
        private readonly ToolInvocationStore $store,
        private readonly ToolRegistry $registry,
        private readonly int $graceSeconds = self::GRACE_SECONDS,
    ) {}

    /** @return int how many invocations were settled as timed out */
    public function sweep(int $limit = 50): int
    {
        $limit = max(1, min($limit, self::MAX_BATCH));
        $now = CarbonImmutable::now('UTC');
        $swept = 0;

        $candidates = ToolInvocation::query()
            ->where('status', ToolInvocationStatus::Running->value)
            ->where('side_effect', ToolSideEffect::Read->value)
            ->whereNotNull('started_at')
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        foreach ($candidates as $candidate) {
            if ($this->settle($candidate, $now)) {
                $swept++;
            }
        }

        return $swept;
    }

    private function settle(ToolInvocation $candidate, CarbonImmutable $now): bool
    {
        try {
            return DB::transaction(function () use ($candidate, $now): bool {
                $row = ToolInvocation::query()->whereKey($candidate->getKey())->lockForUpdate()->first();

                if ($row === null || $row->status !== ToolInvocationStatus::Running || $row->started_at === null) {
                    return false; // the worker settled it first
                }

                $expiry = $this->expiry($row);

                if ($expiry === null || $expiry->greaterThan($now)) {
                    return false; // still within its own declared budget, or an unknown version
                }

                $this->store->timeOut($row, (int) $row->started_at->diffInMilliseconds($now));

                return true;
            });
        } catch (ToolTransitionException) {
            // The worker committed its terminal state between the read and the
            // write: it won, and the sweeper wrote nothing.
            return false;
        }
    }

    /** The deadline recomputed from the IMMUTABLE tool version — never stored, never guessed. */
    private function expiry(ToolInvocation $row): ?CarbonImmutable
    {
        if (! $this->registry->has($row->tool_key, $row->tool_version)) {
            return null;
        }

        $definition = $this->registry->require($row->tool_key, $row->tool_version);

        return CarbonImmutable::instance($row->started_at)->utc()
            ->addMilliseconds($definition->timeoutMs)
            ->addSeconds($this->graceSeconds);
    }
}
