<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\PlanPriceVersionSource;
use App\Exceptions\Billing\PlanPriceOverlapException;
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
 * existing row is closing it once.
 */
final class PlanPriceBook
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function versionFor(int $planId, CarbonImmutable $at): ?PlanPriceVersion
    {
        return PlanPriceVersion::query()
            ->where('plan_id', $planId)
            ->where('effective_from', '<=', $at)
            ->where(static function ($query) use ($at): void {
                $query->whereNull('effective_until')->orWhere('effective_until', '>', $at);
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
    public function recordVersion(Plan $plan, CarbonImmutable $from, PlanPriceVersionSource $source, ?int $createdBy = null): PlanPriceVersion
    {
        return DB::transaction(function () use ($plan, $from, $source, $createdBy): PlanPriceVersion {
            // Serialise every version change for this plan on the parent row.
            $locked = Plan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();

            $overlap = PlanPriceVersion::query()
                ->where('plan_id', $locked->id)
                ->where(static function ($query) use ($from): void {
                    // Any version starting at or after `from` (open or closed), or
                    // a closed version still running after `from`, overlaps. The
                    // one open version that started BEFORE `from` is the period
                    // we close at `from`.
                    $query->where('effective_from', '>=', $from)
                        ->orWhere(static function ($q) use ($from): void {
                            $q->whereNotNull('effective_until')->where('effective_until', '>', $from);
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
                ->where('effective_from', '<', $from)
                ->update(['effective_until' => $from, 'updated_at' => CarbonImmutable::now()]);

            $closed = PlanPriceVersion::query()
                ->where('plan_id', $locked->id)
                ->where('effective_until', $from)
                ->value('id');

            $version = PlanPriceVersion::query()->create([
                'plan_id' => $locked->id,
                'price' => (string) $locked->price,
                'currency' => strtoupper((string) $locked->currency),
                'billing_period' => $locked->billing_period->value,
                'effective_from' => $from,
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
}
