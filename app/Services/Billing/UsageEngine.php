<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Data\Billing\Entitlement;
use App\Data\Billing\UsageDecision;
use App\Enums\UsageDimension;
use App\Enums\UsageOutcome;
use App\Models\UsageCounter;
use App\Models\UsageEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The single, centralized metering engine. All entitlement checks and usage
 * charging go through here — counters are never incremented ad-hoc in WhatsApp
 * or AI code. Channel-agnostic: it only knows subscribers and dimensions.
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
 * This is safe even when the counter row does not exist yet — the failing case
 * for `SELECT ... FOR UPDATE`, which cannot lock a non-existent row. With two
 * "first" messages arriving together:
 *   - both attempt the INSERT; the unique constraint lets exactly one succeed,
 *     the other's statement waits on the conflicting row (PostgreSQL) and then
 *     takes the DO UPDATE branch;
 *   - the DO UPDATE only increments while `used + weight <= cap`, so the counter
 *     can never pass the cap — the loser gets 0 affected rows → LimitReached.
 * A single statement does check-and-increment atomically, so there is no
 * read-then-increment window to exploit, and no reliance on locking a row that
 * isn't there.
 *
 * Idempotency: each charge carries a key (the inbound message id). A duplicate
 * webhook or a job retry inserts the ledger row at most once (unique
 * idempotency_key); a concurrent duplicate's INSERT loses the race and the whole
 * charge rolls back (so it is never counted twice). A caught unique violation is
 * followed only by ROLLBACK, so an aborted PostgreSQL transaction is never
 * reused.
 */
class UsageEngine
{
    public function __construct(private readonly UsageCostCalculator $costs) {}

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
     * Atomically and idempotently charge one usage of a dimension. The
     * authoritative hard-limit gate (safe under concurrency and the
     * counter-creation race — see the class docblock).
     *
     * @param  array<string, mixed>  $meta
     */
    public function charge(
        User $subscriber,
        UsageDimension $dimension,
        string $idempotencyKey,
        array $meta = [],
        int $inputTokens = 0,
        int $outputTokens = 0,
    ): UsageDecision {
        if (! config('billing.enforce', false)) {
            return new UsageDecision(UsageOutcome::NotEnforced, $dimension);
        }

        $ent = $this->entitlement($subscriber, $dimension);

        if (! $ent->entitled) {
            return new UsageDecision(UsageOutcome::Disabled, $dimension);
        }

        [$dayKey, $monthKey] = $this->periodKeys($subscriber);

        try {
            return DB::transaction(function () use (
                $subscriber, $dimension, $idempotencyKey, $meta, $inputTokens, $outputTokens, $ent, $dayKey, $monthKey
            ): UsageDecision {
                // Sequential replay (already committed) — allowed, not re-charged.
                if (UsageEvent::query()->where('idempotency_key', $idempotencyKey)->exists()) {
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

                $cost = $this->costs->cost($dimension, $weight, $inputTokens, $outputTokens);

                try {
                    UsageEvent::create([
                        'user_id' => $subscriber->id,
                        'type' => $dimension->value,
                        'idempotency_key' => $idempotencyKey,
                        'provider' => (string) ($meta['provider'] ?? 'internal'),
                        'model' => $meta['model'] ?? null,
                        'input_units' => $inputTokens,
                        'output_units' => $outputTokens,
                        'quantity' => $weight,
                        'cost' => $cost['cost'],
                        'currency' => $cost['currency'],
                        'metadata' => $meta['metadata'] ?? null,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    // Concurrent duplicate won the ledger insert — roll back the
                    // counter increments so this duplicate is never counted.
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
