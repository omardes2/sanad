<?php

declare(strict_types=1);

use App\Enums\ToolCapability;
use App\Enums\ToolConsentReason;
use App\Enums\ToolInvocationStatus;
use App\Models\Memory;
use App\Models\ToolInvocation;
use App\Models\ToolInvocationEvent;
use App\Models\User;
use App\Services\Tools\ToolConsentService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * GENUINE parallel tests for Phase F2 on PostgreSQL — separate PHP processes,
 * no shared transaction, no in-process claim:
 *
 *  - 6 concurrent claims of one identity ⇒ ONE invocation, ONE execution, ONE
 *    terminal event, ONE audit entry, ONE usage row; the rest replay or report
 *    in flight without touching anything;
 *  - the same identity with DIFFERENT canonical inputs ⇒ one winner, the others
 *    conflict, and nothing runs a second time;
 *  - a revocation racing the authorization ⇒ either it refuses before running
 *    or the run completes, never a half state;
 *  - 6 concurrent terminal settlements ⇒ exactly one wins;
 *  - the stale-running sweeper racing a success ⇒ exactly one terminal status,
 *    never a success AND a timeout;
 *  - repeated replay never adds a second usage row.
 */
beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Real concurrency test requires the pgsql connection.');
    }

    try {
        DB::connection()->getPdo();
    } catch (Throwable) {
        $this->markTestSkipped('PostgreSQL is not reachable.');
    }
});

/** A subscriber who has consented, with memories to read and one stored message. */
function f2Subject(int $memories = 3): array
{
    $subscriber = User::factory()->create(['is_admin' => false]);
    Memory::factory()->count($memories)->create(['user_id' => $subscriber->id, 'content' => 'a note about coffee']);

    auth()->setUser($subscriber);
    app(ToolConsentService::class)->grant($subscriber->id, ToolCapability::MemoryRead, 0, ToolConsentReason::SubscriberRequest);
    auth()->forgetUser();

    return [$subscriber, f2Message($subscriber)];
}

function f2ConsentRun(array $args): Process
{
    $p = new Process(['php', 'artisan', 'sanad:tool-consent-probe', ...$args], base_path());
    $p->start();

    return $p;
}

it('of 6 concurrent claims of ONE identity exactly one executes: one row, one terminal event, one audit, one usage row', function () {
    [$subscriber, $message] = f2Subject();

    try {
        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = f2Run(['execute', (string) $subscriber->id, (string) $message->id, 'coffee']);
        }

        $outcomes = f2Outcomes($processes);
        $row = ToolInvocation::query()->where('subscriber_id', $subscriber->id)->firstOrFail();

        // Exactly one claim; the other five observed the same identity and mutated nothing.
        expect(array_filter($outcomes, fn (string $o): bool => str_starts_with($o, 'claimed:')))->toHaveCount(1)
            ->and(array_filter($outcomes, fn (string $o): bool => str_starts_with($o, 'replay:') || str_starts_with($o, 'in_flight:')))->toHaveCount(5)
            ->and(array_unique(array_map(fn (string $o): string => explode(':', $o)[2] ?? '', $outcomes)))->toHaveCount(1)
            ->and(ToolInvocation::query()->where('subscriber_id', $subscriber->id)->count())->toBe(1)
            ->and($row->status)->toBe(ToolInvocationStatus::Succeeded)
            ->and($row->output)->toBe(['matches' => 3, 'truncated' => false])
            ->and($row->version)->toBe(4)
            ->and(ToolInvocationEvent::query()->where('tool_invocation_id', $row->id)->count())->toBe(4)
            ->and(ToolInvocationEvent::query()->where('tool_invocation_id', $row->id)->where('to_status', 'succeeded')->count())->toBe(1)
            ->and(f2Audits($row))->toHaveCount(1)
            ->and(f2Usage($row))->toHaveCount(1);
    } finally {
        f2Cleanup($subscriber);
    }
});

it('of the same identity with DIFFERENT inputs one canonical input wins and the rest conflict, with no second execution', function () {
    [$subscriber, $message] = f2Subject();

    try {
        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = f2Run(['execute', (string) $subscriber->id, (string) $message->id, $i % 2 === 0 ? 'coffee' : 'tea']);
        }

        $outcomes = f2Outcomes($processes);
        $row = ToolInvocation::query()->where('subscriber_id', $subscriber->id)->firstOrFail();

        // One invocation, one stored input, and the three callers whose input
        // differs from the winner's are refused as a CONFLICT — never executed.
        $hash = fn (string $q): string => hash('sha256', json_encode(['query' => $q], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        expect(ToolInvocation::query()->where('subscriber_id', $subscriber->id)->count())->toBe(1)
            ->and(array_filter($outcomes, fn (string $o): bool => str_starts_with($o, 'claimed:')))->toHaveCount(1)
            ->and(array_filter($outcomes, fn (string $o): bool => str_starts_with($o, 'conflict:')))->toHaveCount(3)
            // The raw words are not stored; the hash of the winner is, and it is one of the two.
            ->and($row->input)->toBe([])
            ->and(in_array($row->input_hash, [$hash('coffee'), $hash('tea')], true))->toBeTrue()
            ->and($row->status)->toBe(ToolInvocationStatus::Succeeded)
            ->and(ToolInvocationEvent::query()->where('tool_invocation_id', $row->id)->count())->toBe(4)
            ->and(f2Audits($row))->toHaveCount(1)
            ->and(f2Usage($row))->toHaveCount(1);
    } finally {
        f2Cleanup($subscriber);
    }
});

it('a revocation racing the authorization either refuses before running or lets the run finish — never a half state', function () {
    [$subscriber, $message] = f2Subject();

    try {
        $execute = f2Run(['execute', (string) $subscriber->id, (string) $message->id, 'coffee']);
        $revoke = f2ConsentRun(['revoke', (string) $subscriber->id, ToolCapability::MemoryRead->value, '1']);

        f2Outcomes([$execute]);
        $revoke->wait();

        $row = ToolInvocation::query()->where('subscriber_id', $subscriber->id)->firstOrFail();

        expect($row->status->isTerminal())->toBeTrue()
            ->and(in_array($row->status, [ToolInvocationStatus::Succeeded, ToolInvocationStatus::Refused], true))->toBeTrue()
            ->and(f2Audits($row))->toHaveCount(1);

        if ($row->status === ToolInvocationStatus::Refused) {
            // Refused before running: nothing executed and nothing was metered.
            expect($row->refusal_reason?->value)->toBeIn(['not_granted', 'consent_revoked'])
                ->and($row->started_at)->toBeNull()
                ->and($row->output)->toBeNull()
                ->and(f2Usage($row))->toHaveCount(0);
        } else {
            expect($row->started_at)->not->toBeNull()
                ->and($row->output)->toBe(['matches' => 3, 'truncated' => false])
                ->and(f2Usage($row))->toHaveCount(1);
        }

        // Whatever happened in the race, a revocation is effective for the NEXT call.
        if (trim($revoke->getOutput()) === 'ok:2') {
            $next = f2Run(['execute', (string) $subscriber->id, (string) f2Message($subscriber)->id, 'coffee']);
            expect(f2Outcomes([$next])[0])->toContain(':refused:');
        }
    } finally {
        f2Cleanup($subscriber);
    }
});

it('of 6 concurrent terminal settlements exactly one wins and the other five write nothing', function () {
    [$subscriber, $message] = f2Subject();

    try {
        $started = f2Run(['start', (string) $subscriber->id, (string) $message->id, 'coffee']);
        expect(f2Outcomes([$started])[0])->toStartWith('running:');

        $row = ToolInvocation::query()->where('subscriber_id', $subscriber->id)->firstOrFail();

        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = f2Run(['settle', (string) $row->id]);
        }

        $outcomes = f2Outcomes($processes);
        $settled = $row->fresh();

        expect(array_filter($outcomes, fn (string $o): bool => $o === 'ok:succeeded'))->toHaveCount(1)
            ->and(array_filter($outcomes, fn (string $o): bool => $o === 'lost'))->toHaveCount(5)
            ->and($settled->status)->toBe(ToolInvocationStatus::Succeeded)
            ->and($settled->version)->toBe(4)
            ->and(ToolInvocationEvent::query()->where('tool_invocation_id', $row->id)->count())->toBe(4)
            ->and(ToolInvocationEvent::query()->where('tool_invocation_id', $row->id)->where('to_status', 'succeeded')->count())->toBe(1)
            ->and(f2Audits($settled))->toHaveCount(1)
            ->and(f2Usage($settled))->toHaveCount(1);
    } finally {
        f2Cleanup($subscriber);
    }
});

it('the sweeper racing a successful settlement leaves exactly one terminal status — never a success AND a timeout', function () {
    [$subscriber, $message] = f2Subject();

    try {
        expect(f2Outcomes([f2Run(['start', (string) $subscriber->id, (string) $message->id, 'coffee'])])[0])->toStartWith('running:');

        $row = ToolInvocation::query()->where('subscriber_id', $subscriber->id)->firstOrFail();

        $settle = f2Run(['settle', (string) $row->id]);
        $sweep = f2Run(['sweep']);
        $outcomes = f2Outcomes([$settle, $sweep]);

        $settled = $row->fresh();
        $terminalEvents = ToolInvocationEvent::query()->where('tool_invocation_id', $row->id)->whereIn('to_status', ['succeeded', 'timed_out'])->get();

        // Exactly one of the two wrote a terminal state.
        expect(in_array($settled->status, [ToolInvocationStatus::Succeeded, ToolInvocationStatus::TimedOut], true))->toBeTrue()
            ->and($terminalEvents)->toHaveCount(1)
            ->and($terminalEvents->first()->to_status)->toBe($settled->status)
            ->and($settled->version)->toBe(4)
            ->and(f2Audits($settled))->toHaveCount(1)
            ->and(f2Usage($settled))->toHaveCount(1)
            // The outcomes are consistent with the winner: either the worker settled
            // and the sweeper found nothing, or the sweeper settled and the worker lost.
            ->and($settled->status === ToolInvocationStatus::Succeeded
                ? ($outcomes[0] === 'ok:succeeded' && $outcomes[1] === 'swept:0')
                : ($outcomes[0] === 'lost' && $outcomes[1] === 'swept:1'))->toBeTrue(implode(' | ', $outcomes));
    } finally {
        f2Cleanup($subscriber);
    }
});

it('never adds a second usage row or a second audit entry however many times the identity is replayed', function () {
    [$subscriber, $message] = f2Subject();

    try {
        expect(f2Outcomes([f2Run(['execute', (string) $subscriber->id, (string) $message->id, 'coffee'])])[0])->toStartWith('claimed:succeeded:');

        $row = ToolInvocation::query()->where('subscriber_id', $subscriber->id)->firstOrFail();

        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = f2Run(['execute', (string) $subscriber->id, (string) $message->id, 'coffee']);
        }

        $outcomes = f2Outcomes($processes);

        expect(array_filter($outcomes, fn (string $o): bool => str_starts_with($o, 'replay:succeeded:')))->toHaveCount(6)
            ->and(ToolInvocation::query()->where('subscriber_id', $subscriber->id)->count())->toBe(1)
            ->and($row->fresh()->version)->toBe(4)
            ->and(ToolInvocationEvent::query()->where('tool_invocation_id', $row->id)->count())->toBe(4)
            ->and(f2Audits($row))->toHaveCount(1)
            ->and(f2Usage($row))->toHaveCount(1)
            ->and(f2Usage($row)->first()->tool_invocation_ref)->toBe((string) $row->id);
    } finally {
        f2Cleanup($subscriber);
    }
});

it('holds ONE slot against concurrent claims that propose DIFFERENT tools for it', function () {
    [$subscriber, $message] = f2Subject();

    try {
        // Six processes racing for the same slot `msg:<id>:call:1`: three propose
        // memory.read@1, three propose a different version of the contract.
        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = f2Run(['claim', (string) $subscriber->id, (string) $message->id, 'memory.read', $i % 2 === 0 ? '1' : '2', 'coffee']);
        }

        $outcomes = f2Outcomes($processes);
        $rows = ToolInvocation::query()->where('subscriber_id', $subscriber->id)->get();
        $row = $rows->firstOrFail();

        // ONE invocation for the slot; the three that proposed the other version
        // conflicted against the stored claim facts and wrote nothing.
        expect($rows)->toHaveCount(1)
            ->and($row->idempotency_key)->toBe('msg:'.$message->id.':call:1')
            ->and(array_filter($outcomes, fn (string $o): bool => str_starts_with($o, 'claimed:')))->toHaveCount(1)
            ->and(array_filter($outcomes, fn (string $o): bool => str_starts_with($o, 'conflict:')))->toHaveCount(3)
            ->and(array_unique(array_map(fn (string $o): string => explode(':', $o)[1], $outcomes)))->toEqual([(string) $row->id])
            ->and(in_array($row->tool_version, [1, 2], true))->toBeTrue()
            ->and($row->status)->toBe(ToolInvocationStatus::Planned)
            // A claim is a claim: one event, and nothing terminal happened.
            ->and(ToolInvocationEvent::query()->where('tool_invocation_id', $row->id)->count())->toBe(1)
            ->and(f2Audits($row))->toHaveCount(0)
            ->and(f2Usage($row))->toHaveCount(0);
    } finally {
        f2Cleanup($subscriber);
    }
});
