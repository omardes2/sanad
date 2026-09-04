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
 * Concurrency: charge() runs inside a transaction and locks the per-window
 * counter rows (SELECT ... FOR UPDATE) before checking and incrementing, so
 * concurrent workers cannot push a counter past its hard limit. Idempotency:
 * each charge carries a key (the inbound message id); a duplicate webhook or a
 * job retry records/charges at most once (unique idempotency_key on the ledger).
 */
class UsageEngine
{
    public function __construct(private readonly UsageCostCalculator $costs) {}

    /**
     * Non-locking pre-check used BEFORE calling a metered provider, so we don't
     * call the AI provider when the subscriber is already over the limit.
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
     * Atomically and idempotently charge one usage of a dimension. This is the
     * authoritative hard-limit gate (safe under concurrency).
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

        return DB::transaction(function () use (
            $subscriber, $dimension, $idempotencyKey, $meta, $inputTokens, $outputTokens, $ent, $dayKey, $monthKey
        ): UsageDecision {
            // Idempotent replay (duplicate webhook / retry) — already charged.
            if (UsageEvent::query()->where('idempotency_key', $idempotencyKey)->exists()) {
                return new UsageDecision(UsageOutcome::AlreadyCharged, $dimension);
            }

            $weight = $ent->weight;

            /** @var list<UsageCounter> $locked */
            $locked = [];

            foreach ($this->cappedWindows($ent, $dayKey, $monthKey) as [$period, $periodKey, $cap]) {
                $counter = $this->lockCounter($subscriber, $dimension, $period, $periodKey);

                if ($counter->used + $weight > $cap) {
                    // Rollback (no increment, no ledger row) and report the limit.
                    return new UsageDecision(UsageOutcome::LimitReached, $dimension, $period);
                }

                $locked[] = $counter;
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
                // Concurrent replay inserted the same key first — do not double charge.
                return new UsageDecision(UsageOutcome::AlreadyCharged, $dimension);
            }

            foreach ($locked as $counter) {
                $counter->used += $weight;
                $counter->save();
            }

            return UsageDecision::allow($dimension);
        });
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
     * @param  array{0: string, 1: string, 2: int}  ...$_
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

    private function lockCounter(User $subscriber, UsageDimension $dimension, string $period, string $periodKey): UsageCounter
    {
        $keys = [
            'subscriber_id' => $subscriber->id,
            'dimension' => $dimension->value,
            'period' => $period,
            'period_key' => $periodKey,
        ];

        $counter = UsageCounter::query()->where($keys)->lockForUpdate()->first();

        if ($counter !== null) {
            return $counter;
        }

        try {
            return UsageCounter::create($keys + ['used' => 0]);
        } catch (UniqueConstraintViolationException) {
            return UsageCounter::query()->where($keys)->lockForUpdate()->firstOrFail();
        }
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
