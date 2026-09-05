<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Data\Billing\Entitlement;
use App\Data\Billing\UsageDecision;
use App\Enums\UsageDimension;
use App\Enums\UsageOutcome;
use App\Models\UsageCharge;
use App\Models\UsageCounter;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The centralized ENFORCEMENT engine: entitlement checks, limits, usage
 * counters. It decides allow / deny and consumes quota — nothing else.
 *
 * It does NOT write the cost ledger (usage_events). That is UsageRecorder's
 * job and it runs whether or not enforcement is on. Both are idempotent on
 * their own, so a retry re-runs each safely and neither depends on the other:
 * a counter failure can never erase a cost we already incurred.
 *
 * Everything here is gated by billing.enforce: when it is off, check() and
 * charge() report NotEnforced and touch nothing.
 *
 * Concurrency & the counter-creation race
 * ---------------------------------------
 * The hard limit is enforced by an ATOMIC CONDITIONAL UPSERT per window:
 *
 *   INSERT ... VALUES (used = weight)
 *   ON CONFLICT (subscriber, dimension, period, period_key)
 *   DO UPDATE SET used = used + weight
 *   WHERE used + weight <= cap
 *
 * Safe even when the counter row does not exist yet (the failing case for
 * SELECT ... FOR UPDATE): the unique constraint serialises creation and the
 * conditional increment can never pass the cap — the loser gets 0 affected
 * rows → LimitReached. Check-and-increment is one statement, so there is no
 * read-then-increment window.
 *
 * Idempotency: each charge inserts a usage_charges row (unique idempotency_key)
 * in the SAME transaction as the counter increments. A sequential replay is
 * detected up front (AlreadyCharged, allowed, not re-counted); a concurrent
 * duplicate loses the unique insert and the whole charge rolls back, so it is
 * never counted twice. A caught unique violation is followed only by ROLLBACK.
 */
class UsageEngine
{
    /**
     * Non-locking pre-check used BEFORE calling a metered provider, so we don't
     * call the AI provider when the subscriber is already over the limit. This
     * is advisory only — charge() is the authoritative, race-safe gate.
     */
    public function check(User $subscriber, UsageDimension $dimension): UsageDecision
    {
        if (! config('billing.enforce', false)) {
            return new UsageDecision(UsageOutcome::NotEnforced, $dimension);
        }

        $ent = $this->entitlement($subscriber, $dimension);

        if (! $ent->entitled) {
            return new UsageDecision(UsageOutcome::Disabled, $dimension);
        }

        [$dayKey, $monthKey] = $this->periodKeys($subscriber);

        if ($ent->dailyLimit !== null
            && $this->used($subscriber, $dimension, 'day', $dayKey) + $ent->weight > $ent->dailyLimit) {
            return new UsageDecision(UsageOutcome::LimitReached, $dimension, 'day');
        }

        if ($ent->monthlyLimit !== null
            && $this->used($subscriber, $dimension, 'month', $monthKey) + $ent->weight > $ent->monthlyLimit) {
            return new UsageDecision(UsageOutcome::LimitReached, $dimension, 'month');
        }

        return UsageDecision::allow($dimension);
    }

    /**
     * Atomically and idempotently consume one unit of quota for a dimension —
     * the authoritative hard-limit gate (see the class docblock). Quota only:
     * the cost of the operation is recorded separately by UsageRecorder.
     */
    public function charge(User $subscriber, UsageDimension $dimension, string $idempotencyKey): UsageDecision
    {
        if (! config('billing.enforce', false)) {
            return new UsageDecision(UsageOutcome::NotEnforced, $dimension);
        }

        $ent = $this->entitlement($subscriber, $dimension);

        if (! $ent->entitled) {
            return new UsageDecision(UsageOutcome::Disabled, $dimension);
        }

        [$dayKey, $monthKey] = $this->periodKeys($subscriber);

        try {
            return DB::transaction(function () use ($subscriber, $dimension, $idempotencyKey, $ent, $dayKey, $monthKey): UsageDecision {
                // Sequential replay (already committed) — allowed, not re-counted.
                if (UsageCharge::query()->where('idempotency_key', $idempotencyKey)->exists()) {
                    return new UsageDecision(UsageOutcome::AlreadyCharged, $dimension);
                }

                $weight = $ent->weight;

                // Atomic conditional upsert per capped window. Any window that
                // would exceed its cap aborts the whole charge (rollback).
                foreach ($this->cappedWindows($ent, $dayKey, $monthKey) as [$period, $periodKey, $cap]) {
                    if (! $this->increment($subscriber, $dimension, $period, $periodKey, $weight, $cap)) {
                        throw new UsageChargeAborted(UsageOutcome::LimitReached, $period);
                    }
                }

                try {
                    UsageCharge::create([
                        'subscriber_id' => $subscriber->id,
                        'dimension' => $dimension->value,
                        'idempotency_key' => $idempotencyKey,
                        'weight' => $weight,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    // Concurrent duplicate won the insert — roll back the counter
                    // increments so this duplicate is never counted.
                    throw new UsageChargeAborted(UsageOutcome::AlreadyCharged);
                }

                return UsageDecision::allow($dimension);
            });
        } catch (UsageChargeAborted $aborted) {
            return new UsageDecision($aborted->outcome, $dimension, $aborted->window);
        }
    }

    public function entitlement(User $subscriber, UsageDimension $dimension): Entitlement
    {
        return app(SubscriptionService::class)->entitlement($subscriber, $dimension);
    }

    /**
     * Current used counts for a dimension (for admin display).
     *
     * @return array{daily: int, monthly: int}
     */
    public function usage(User $subscriber, UsageDimension $dimension): array
    {
        [$dayKey, $monthKey] = $this->periodKeys($subscriber);

        return [
            'daily' => $this->used($subscriber, $dimension, 'day', $dayKey),
            'monthly' => $this->used($subscriber, $dimension, 'month', $monthKey),
        ];
    }

    /**
     * Atomic check-and-increment for one window. Returns true when the weight
     * was consumed, false when the cap would be exceeded. Safe even if the row
     * does not exist yet (INSERT ... ON CONFLICT), and cross-database (PostgreSQL
     * and SQLite both support the conditional upsert).
     */
    private function increment(User $subscriber, UsageDimension $dimension, string $period, string $periodKey, int $weight, int $cap): bool
    {
        // A fresh INSERT is not covered by the DO UPDATE ... WHERE cap guard, so
        // refuse up front when even the first unit would exceed the cap.
        if ($weight > $cap) {
            return false;
        }

        $now = CarbonImmutable::now()->toDateTimeString();

        $affected = DB::affectingStatement(
            'insert into usage_counters (subscriber_id, dimension, period, period_key, used, created_at, updated_at)'
            .' values (?, ?, ?, ?, ?, ?, ?)'
            .' on conflict (subscriber_id, dimension, period, period_key)'
            .' do update set used = usage_counters.used + ?, updated_at = ?'
            .' where usage_counters.used + ? <= ?',
            [$subscriber->id, $dimension->value, $period, $periodKey, $weight, $now, $now, $weight, $now, $weight, $cap],
        );

        return $affected >= 1;
    }

    /**
     * @return list<array{0: string, 1: string, 2: int}>
     */
    private function cappedWindows(Entitlement $ent, string $dayKey, string $monthKey): array
    {
        $windows = [];

        if ($ent->dailyLimit !== null) {
            $windows[] = ['day', $dayKey, $ent->dailyLimit];
        }

        if ($ent->monthlyLimit !== null) {
            $windows[] = ['month', $monthKey, $ent->monthlyLimit];
        }

        return $windows;
    }

    private function used(User $subscriber, UsageDimension $dimension, string $period, string $periodKey): int
    {
        return (int) (UsageCounter::query()
            ->where('subscriber_id', $subscriber->id)
            ->where('dimension', $dimension->value)
            ->where('period', $period)
            ->where('period_key', $periodKey)
            ->value('used') ?? 0);
    }

    /**
     * @return array{0: string, 1: string} [dayKey, monthKey] in the subscriber's timezone
     */
    private function periodKeys(User $subscriber): array
    {
        $tz = $subscriber->timezone ?: config('sanad.default_user_timezone', 'UTC');
        $now = CarbonImmutable::now($tz);

        return [$now->format('Y-m-d'), $now->format('Y-m')];
    }
}
