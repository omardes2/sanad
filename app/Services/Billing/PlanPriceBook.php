<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\PlanPriceVersionSource;
use App\Exceptions\Billing\PlanPriceOverlapException;
use App\Exceptions\Billing\StalePlanPriceVersionException;
use App\Models\Plan;
use App\Models\PlanPriceVersion;
use App\Services\Audit\AuditLogger;
use App\Support\Audit\AuditActions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Plan price history (Phase E0), mirroring PriceBook for model prices:
 *
 *  - versionFor(plan, at): the version in force at an instant (from ≤ at and
 *    until IS NULL or until > at) — NULL before the first version;
 *  - recordVersion(plan, from, source): under the PARENT plan row lock, refuse
 *    any overlap (a version that starts at or after `from`, or a closed one
 *    that ends after `from`), close the open version at `from`, open the new
 *    one with the plan's CURRENT terms and audit it — all in the caller's
 *    transaction (or its own when none is open).
 *
 * Versions are never rewritten, split or back-dated; the only write to an
 * existing row is closing it once. Period boundaries are compared and stored
 * with MICROSECOND precision (bound explicitly as "Y-m-d H:i:s.u" so the query
 * grammar never truncates them), which is what makes an immediate retry after
 * a stale conflict deterministic — no clock spacing is ever required.
 *
 * Stale protection (admin edits): the caller passes the id of the open version
 * it loaded the form from (NULL when the plan had none); after the lock the
 * current open version is re-read and a mismatch is refused BEFORE anything is
 * written — no close, no new version, no audit.
 */
final class PlanPriceBook
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function versionFor(int $planId, CarbonImmutable $at): ?PlanPriceVersion
    {
        $atValue = self::boundary($at);

        return PlanPriceVersion::query()
            ->where('plan_id', $planId)
            ->where('effective_from', '<=', $atValue)
            ->where(static function ($query) use ($atValue): void {
                $query->whereNull('effective_until')->orWhere('effective_until', '>', $atValue);
            })
            ->orderByDesc('effective_from')
            ->first();
    }

    public function openVersionFor(int $planId): ?PlanPriceVersion
    {
        return PlanPriceVersion::query()->where('plan_id', $planId)->whereNull('effective_until')->first();
    }

    /**
     * @throws PlanPriceOverlapException
     */
    /**
     * Under the plan row lock (the caller must hold it), refuse to continue
     * when the open version is not the one the admin loaded.
     *
     * @throws StalePlanPriceVersionException
     */
    public function assertOpenVersionIs(int $planId, ?int $expectedOpenVersionId): void
    {
        $current = $this->openVersionFor($planId)?->id;

        if ($current !== $expectedOpenVersionId) {
            throw StalePlanPriceVersionException::forVersion($expectedOpenVersionId, $current);
        }
    }

    /**
     * @param  int|null  $expectedOpenVersionId  the open version the caller acted on (NULL = none); checked only when $enforceExpected
     *
     * @throws PlanPriceOverlapException
     * @throws StalePlanPriceVersionException
     */
    public function recordVersion(Plan $plan, CarbonImmutable $from, PlanPriceVersionSource $source, ?int $createdBy = null, ?int $expectedOpenVersionId = null, bool $enforceExpected = false): PlanPriceVersion
    {
        return DB::transaction(function () use ($plan, $from, $source, $createdBy, $expectedOpenVersionId, $enforceExpected): PlanPriceVersion {
            // Serialise every version change for this plan on the parent row.
            $locked = Plan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();

            if ($enforceExpected) {
                $this->assertOpenVersionIs($locked->id, $expectedOpenVersionId);
            }

            $fromValue = self::boundary($from);

            $overlap = PlanPriceVersion::query()
                ->where('plan_id', $locked->id)
                ->where(static function ($query) use ($fromValue): void {
                    // Any version starting at or after `from` (open or closed), or
                    // a closed version still running after `from`, overlaps. The
                    // one open version that started BEFORE `from` is the period
                    // we close at `from`. Strict comparisons at microsecond
                    // precision: a closed period is always [from, until) with
                    // until > from — never zero-length.
                    $query->where('effective_from', '>=', $fromValue)
                        ->orWhere(static function ($q) use ($fromValue): void {
                            $q->whereNotNull('effective_until')->where('effective_until', '>', $fromValue);
                        });
                })
                ->orderBy('effective_from')
                ->first();

            if ($overlap !== null) {
                throw PlanPriceOverlapException::for($locked, $overlap, $from);
            }

            PlanPriceVersion::query()
                ->where('plan_id', $locked->id)
                ->whereNull('effective_until')
                ->where('effective_from', '<', $fromValue)
                ->update(['effective_until' => $fromValue, 'updated_at' => CarbonImmutable::now()->format(PlanPriceVersion::PERIOD_FORMAT)]);

            $closed = PlanPriceVersion::query()
                ->where('plan_id', $locked->id)
                ->where('effective_until', $fromValue)
                ->value('id');

            $version = PlanPriceVersion::query()->create([
                'plan_id' => $locked->id,
                'price' => (string) $locked->price,
                'currency' => strtoupper((string) $locked->currency),
                'billing_period' => $locked->billing_period->value,
                'effective_from' => $fromValue,
                'effective_until' => null,
                'source' => $source->value,
                'created_by' => $createdBy,
            ]);

            $this->audit->record(AuditActions::PlanPriceVersioned, $version, [
                'terms' => ['from' => null, 'to' => $version->terms()],
            ], [
                'plan_id' => $locked->id,
                'slug' => $locked->slug,
                'closed_version_id' => $closed,
                'effective_from' => $from->toIso8601String(),
                'source' => $source->value,
            ]);

            return $version;
        });
    }

    /** A boundary as the exact string both engines store and compare (microseconds kept). */
    public static function boundary(CarbonImmutable $at): string
    {
        return $at->format(PlanPriceVersion::PERIOD_FORMAT);
    }
}
