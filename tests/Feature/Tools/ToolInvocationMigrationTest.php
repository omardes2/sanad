<?php

declare(strict_types=1);

use App\Enums\ToolCapability;
use App\Enums\ToolConsentReason;
use App\Models\User;
use App\Services\Tools\ToolConsentService;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Phase F2 — the two invocation migrations: exactly two, last in the sequence,
 * reversible together, and on PostgreSQL the row-level coherence of the
 * lifecycle is a database constraint, not only a service rule.
 */
it('is the 61st and 62nd migration, creates both tables with their identity and indexes, and rolls back and forward cleanly', function () {
    $files = glob(database_path('migrations/*.php'));

    expect($files)->toHaveCount(62)
        ->and(basename($files[60]))->toBe('2026_09_08_000101_create_tool_invocations_table.php')
        ->and(basename($files[61]))->toBe('2026_09_08_000102_create_tool_invocation_events_table.php')
        ->and(Schema::hasTable('tool_invocations'))->toBeTrue()
        ->and(Schema::hasTable('tool_invocation_events'))->toBeTrue()
        ->and(Schema::hasIndex('tool_invocations', 'tool_invocations_idempotency_key_unique'))->toBeTrue()
        ->and(Schema::hasIndex('tool_invocations', 'tool_invocations_subscriber_created_idx'))->toBeTrue()
        ->and(Schema::hasIndex('tool_invocations', 'tool_invocations_status_created_idx'))->toBeTrue()
        ->and(Schema::hasIndex('tool_invocation_events', 'tool_invocation_events_invocation_seq_unique'))->toBeTrue();

    $columns = collect(Schema::getColumns('tool_invocations'))->pluck('name')->all();
    sort($columns);
    expect($columns)->toBe([
        'call_index', 'capability', 'conversation_id', 'created_at', 'duration_ms', 'failure_kind',
        'finished_at', 'id', 'idempotency_key', 'input', 'input_hash', 'message_id', 'output',
        'refusal_reason', 'side_effect', 'started_at', 'status', 'subscriber_id', 'tool_key',
        'tool_version', 'updated_at', 'version',
    ]);

    // No timeout is duplicated onto the row: it is a property of the immutable
    // tool version and stays recoverable from the registry.
    expect(in_array('timeout_ms', $columns, true))->toBeFalse();

    $eventColumns = collect(Schema::getColumns('tool_invocation_events'))->pluck('name')->all();
    sort($eventColumns);
    expect($eventColumns)->toBe(['actor_ref', 'created_at', 'detail', 'from_status', 'id', 'occurred_at', 'reason_code', 'seq', 'to_status', 'tool_invocation_id'])
        // Append-only: there is no updated_at to move.
        ->and(in_array('updated_at', $eventColumns, true))->toBeFalse();

    $subscriber = User::factory()->create();
    auth()->setUser($subscriber);
    app(ToolConsentService::class)->grant($subscriber->id, ToolCapability::MemoryRead, 0, ToolConsentReason::SubscriberRequest);
    auth()->forgetUser();

    $row = f2Executor()->call(f2Message($subscriber), 'memory.read@1', ['query' => 'coffee'])->invocation;

    expect(DB::table('tool_invocations')->count())->toBe(1)
        ->and(DB::table('tool_invocation_events')->where('tool_invocation_id', $row->id)->count())->toBe(4);

    Artisan::call('migrate:rollback', ['--step' => 2, '--force' => true]);

    expect(Schema::hasTable('tool_invocations'))->toBeFalse()
        ->and(Schema::hasTable('tool_invocation_events'))->toBeFalse()
        // Everything the previous phases created is still there, F1 included.
        ->and(Schema::hasTable('tool_consents'))->toBeTrue()
        ->and(Schema::hasTable('memories'))->toBeTrue()
        ->and(Schema::hasTable('usage_events'))->toBeTrue()
        ->and(Schema::hasTable('audit_logs'))->toBeTrue()
        ->and(DB::table('tool_consents')->count())->toBe(1)
        ->and(DB::table('migrations')->count())->toBe(60);

    Artisan::call('migrate', ['--force' => true]);

    expect(Schema::hasTable('tool_invocations'))->toBeTrue()
        ->and(Schema::hasIndex('tool_invocations', 'tool_invocations_idempotency_key_unique'))->toBeTrue()
        ->and(DB::table('tool_invocations')->count())->toBe(0)
        ->and(DB::table('tool_invocation_events')->count())->toBe(0)
        ->and(DB::table('migrations')->count())->toBe(62);

    // The same call is claimable again after the round trip.
    expect(f2Executor()->call(f2Message($subscriber), 'memory.read@1', ['query' => 'coffee'])->invocation->status->value)->toBe('succeeded');
});

it('enforces one invocation per identity at the database level', function () {
    $subscriber = User::factory()->create();
    auth()->setUser($subscriber);
    app(ToolConsentService::class)->grant($subscriber->id, ToolCapability::MemoryRead, 0, ToolConsentReason::SubscriberRequest);
    auth()->forgetUser();

    $row = f2Executor()->call(f2Message($subscriber), 'memory.read@1', ['query' => 'coffee'])->invocation;

    $duplicate = fn () => DB::table('tool_invocations')->insert([
        'subscriber_id' => $subscriber->id, 'tool_key' => 'memory.read', 'tool_version' => 1,
        'capability' => 'memory.read', 'side_effect' => 'read',
        'idempotency_key' => $row->idempotency_key, 'input_hash' => str_repeat('a', 64),
        'input' => '{}', 'status' => 'planned', 'call_index' => 1, 'version' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => DB::transaction($duplicate))->toThrow(UniqueConstraintViolationException::class)
        ->and(DB::table('tool_invocations')->count())->toBe(1);

    // And one event per step of one invocation.
    $event = DB::table('tool_invocation_events')->where('tool_invocation_id', $row->id)->first();

    expect(fn () => DB::transaction(fn () => DB::table('tool_invocation_events')->insert([
        'tool_invocation_id' => $row->id, 'seq' => $event->seq, 'from_status' => null,
        'to_status' => 'planned', 'actor_ref' => 'system',
        'occurred_at' => now(), 'created_at' => now(),
    ])))->toThrow(UniqueConstraintViolationException::class)
        ->and(DB::table('tool_invocation_events')->where('tool_invocation_id', $row->id)->count())->toBe(4);
});

it('on PostgreSQL keeps the lifecycle coherent as a database constraint, not only as a service rule', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('CHECK constraints are declared on PostgreSQL only.');
    }

    $subscriber = User::factory()->create();
    $base = [
        'subscriber_id' => $subscriber->id, 'tool_key' => 'memory.read', 'tool_version' => 1,
        'capability' => 'memory.read', 'side_effect' => 'read', 'input_hash' => str_repeat('a', 64),
        'input' => '{}', 'call_index' => 1, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
    ];

    $refused = [
        'unknown status' => ['status' => 'cancelled'],               // F2 has no cancelled state at all
        'zero version' => ['status' => 'planned', 'version' => 0],
        'zero call index' => ['status' => 'planned', 'call_index' => 0],
        'output without success' => ['status' => 'planned', 'output' => '{"matches":1}'],
        'success without output' => ['status' => 'succeeded', 'started_at' => now(), 'finished_at' => now()],
        'failure without kind' => ['status' => 'failed', 'started_at' => now(), 'finished_at' => now()],
        'refusal without reason' => ['status' => 'refused'],
        'refusal that started' => ['status' => 'refused', 'refusal_reason' => 'not_granted', 'started_at' => now()],
    ];

    foreach ($refused as $label => $overrides) {
        // array_merge, not `+`: the overrides must win over the valid baseline.
        $row = array_merge($base, $overrides, ['idempotency_key' => 'k:'.md5($label)]);

        expect(fn () => DB::transaction(fn () => DB::table('tool_invocations')->insert($row)))
            ->toThrow(QueryException::class, 'check');
    }

    expect(DB::table('tool_invocations')->count())->toBe(0);
});
