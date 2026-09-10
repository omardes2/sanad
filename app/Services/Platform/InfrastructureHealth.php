<?php

declare(strict_types=1);

namespace App\Services\Platform;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

/**
 * Read-only infrastructure health, extracted from `WhatsAppStatus` (Phase 0D)
 * so the readiness screen and the overview share ONE implementation instead of
 * three copies of the same try/catch.
 *
 * Every method has a third answer. `unavailable` / `null` means "we could not
 * ask", which is NOT the same as "it is down" — a readiness screen that turns a
 * failed lookup into a red light teaches operators to ignore it.
 *
 * Nothing here writes, and nothing here reads a credential.
 */
/*
 * Deliberately NOT `final`, unlike the domain classes around it. This is an
 * adapter over Horizon and Redis whose entire job is to be substituted at the
 * container boundary — a readiness test has to be able to say "the queue backend
 * is unreachable but Horizon is unknown" without a Redis server, and that case
 * is exactly the one the gate most needs to get right.
 */
class InfrastructureHealth
{
    /** The queues this platform actually dispatches onto. */
    public const QUEUES = ['webhooks', 'messages', 'default'];

    /** 'running' | 'inactive' | 'unavailable' — never throws. */
    public function horizon(): string
    {
        try {
            return count(app(MasterSupervisorRepository::class)->all()) > 0 ? 'running' : 'inactive';
        } catch (Throwable) {
            return 'unavailable';
        }
    }

    /**
     * Can we reach the queue backend? A failed ping means UNREACHABLE, which is
     * a fact, not an unknown — unlike Horizon's repository, which can be absent
     * simply because Horizon is not installed in this environment.
     */
    public function redis(): bool
    {
        try {
            Redis::connection()->ping();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Pending job count per known queue. A null value means the depth could not
     * be read — rendered as "—", never as zero.
     *
     * @return array<string, int|null>
     */
    public function queueDepths(): array
    {
        $sizes = [];

        foreach (self::QUEUES as $queue) {
            try {
                $sizes[$queue] = Queue::size($queue);
            } catch (Throwable) {
                $sizes[$queue] = null;
            }
        }

        return $sizes;
    }

    /**
     * Could we reach the queue backend at all? Used by the launch gate, where
     * "queue unavailable" is a STRUCTURAL blocker while a deep queue is only an
     * operational warning.
     */
    public function queueReachable(): bool
    {
        return $this->redis();
    }
}
