<?php

declare(strict_types=1);

use App\Enums\ToolCapability;
use App\Enums\ToolConsentReason;
use App\Enums\ToolInvocationStatus;
use App\Enums\ToolSideEffect;
use App\Models\Memory;
use App\Models\ToolInvocation;
use App\Models\User;
use App\Services\Tools\StaleInvocationSweeper;
use App\Services\Tools\ToolConsentService;
use App\Services\Tools\ToolInvocationStore;
use App\Support\Tools\ToolRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Phase F2 performance guards: one invocation costs a fixed number of queries
 * whatever the size of the tables, the read itself is bounded by the tool's own
 * limit and never by the row count, and on PostgreSQL the two reads that matter
 * — a subscriber's invocation history and the stale-running sweep — use the two
 * declared indexes rather than a sequential scan. No third index is needed.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-08 09:00:00', 'UTC'));
    $this->subscriber = User::factory()->create();

    auth()->setUser($this->subscriber);
    app(ToolConsentService::class)->grant($this->subscriber->id, ToolCapability::MemoryRead, 0, ToolConsentReason::SubscriberRequest);
    auth()->forgetUser();
});

function f2Queries(callable $fn): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $fn();
    $n = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $n;
}

it('costs the same number of queries with 5 memories and with 500, and a replay costs strictly fewer', function () {
    // Distinct sentences: two memories with the same normalised text in one
    // category are ONE memory, so a fixture that repeated itself could not exist.
    for ($i = 0; $i < 5; $i++) {
        Memory::factory()->create(['user_id' => $this->subscriber->id, 'content' => "a note {$i} about coffee"]);
    }

    // One warm-up call so cold caches are not counted as invocation work.
    f2Executor()->call(f2Message($this->subscriber), 'memory.read@1', ['query' => 'coffee']);

    $smallMessage = f2Message($this->subscriber);
    $small = f2Queries(fn () => f2Executor()->call($smallMessage, 'memory.read@1', ['query' => 'coffee']));

    for ($i = 0; $i < 495; $i++) {
        Memory::factory()->create(['user_id' => $this->subscriber->id, 'content' => "note {$i} about coffee"]);
    }

    // The read is bounded by the subscriber's active-memory ceiling, so the
    // work it does — including the decryption — cannot grow with the table.

    $message = f2Message($this->subscriber);
    $large = f2Queries(fn () => f2Executor()->call($message, 'memory.read@1', ['query' => 'coffee']));

    expect($large)->toBe($small)
        // The read is bounded by the tool's limit, not by the table.
        ->and(ToolInvocation::query()->latest('id')->first()->output)->toBe(['matches' => 10, 'truncated' => true]);

    // A replay observes the identity and stops: no transition, no audit, no ledger write.
    $replay = f2Queries(fn () => f2Executor()->call($message, 'memory.read@1', ['query' => 'coffee']));
    expect($replay)->toBeLessThan($large);
});

it('on PostgreSQL reads a subscriber invocation history and sweeps stale reads through the declared indexes', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('EXPLAIN plans are asserted on PostgreSQL only.');
    }

    // A realistic table: many invocations across many subscribers.
    $rows = [];
    $now = CarbonImmutable::now('UTC');

    for ($i = 0; $i < 6000; $i++) {
        $rows[] = [
            'subscriber_id' => $this->subscriber->id,
            'tool_key' => 'memory.read',
            'tool_version' => 1,
            'capability' => ToolCapability::MemoryRead->value,
            'side_effect' => ToolSideEffect::Read->value,
            'idempotency_key' => 'msg:'.$i.':call:1',
            'input_hash' => hash('sha256', (string) $i),
            'input' => '{}',
            'input_fields' => '["query"]',
            'status' => $i % 20 === 0 ? ToolInvocationStatus::Running->value : ToolInvocationStatus::Succeeded->value,
            'output' => $i % 20 === 0 ? null : '{"matches":1,"truncated":false}',
            'call_index' => 1,
            'version' => 4,
            'started_at' => $now->subMinutes($i % 500),
            'finished_at' => $i % 20 === 0 ? null : $now->subMinutes($i % 500),
            'created_at' => $now->subMinutes($i % 500),
            'updated_at' => $now,
        ];
    }

    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('tool_invocations')->insert($chunk);
    }

    DB::statement('ANALYZE tool_invocations');

    $history = DB::select('EXPLAIN (ANALYZE, COSTS OFF) SELECT * FROM tool_invocations WHERE subscriber_id = ? ORDER BY created_at DESC, id DESC LIMIT 25', [$this->subscriber->id]);
    $sweep = DB::select("EXPLAIN (ANALYZE, COSTS OFF) SELECT * FROM tool_invocations WHERE status = 'running' AND side_effect = 'read' AND started_at IS NOT NULL ORDER BY created_at LIMIT 50");

    $plan = fn (array $rows): string => implode("\n", array_map(fn ($r) => trim((string) $r->{'QUERY PLAN'}), $rows));

    fwrite(STDOUT, "[EXPLAIN subscriber history]\n".$plan($history)."\n[EXPLAIN stale-running sweep]\n".$plan($sweep)."\n");

    expect($plan($history))->toContain('tool_invocations_subscriber_created_idx')
        ->and($plan($sweep))->toContain('tool_invocations_status_created_idx')
        // Neither read falls back to reading the table.
        ->and($plan($history))->not->toContain('Seq Scan on tool_invocations')
        ->and($plan($sweep))->not->toContain('Seq Scan on tool_invocations');

    // The sweep candidate query is bounded: the batch is what it reads, not the table.
    $sweeper = new StaleInvocationSweeper(app(ToolInvocationStore::class), app(ToolRegistry::class), 0);
    $queries = f2Queries(fn () => $sweeper->sweep(limit: 1));
    expect($queries)->toBeLessThan(20);
});
