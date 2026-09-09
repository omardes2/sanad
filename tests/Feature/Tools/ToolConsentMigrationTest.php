<?php

declare(strict_types=1);

use App\Enums\ToolCapability;
use App\Enums\ToolConsentReason;
use App\Models\User;
use App\Services\Tools\ToolConsentService;
use App\Support\Tools\EvidenceRef;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Phase F1 ships exactly ONE migration — `tool_consents` — and it is fully
 * reversible on both engines: down drops the table and nothing else, up
 * rebuilds it with its unique identity and its index. No other table, column
 * or index of E0–E5 is touched.
 */
it('is the 60th of 63 migrations, creates tool_consents with its identity and index, and rolls back and forward cleanly', function () {
    $files = glob(database_path('migrations/*.php'));

    expect($files)->toHaveCount(63)
        ->and(basename($files[count($files) - 4]))->toBe('2026_09_07_000101_create_tool_consents_table.php')
        ->and(Schema::hasTable('tool_consents'))->toBeTrue()
        ->and(Schema::hasIndex('tool_consents', 'tool_consents_subscriber_capability_unique'))->toBeTrue()
        ->and(Schema::hasIndex('tool_consents', 'tool_consents_capability_status_idx'))->toBeTrue();

    $columns = collect(Schema::getColumns('tool_consents'))->pluck('name')->all();
    sort($columns);
    expect($columns)->toBe(['capability', 'created_at', 'evidence_ref', 'granted_at', 'id', 'reason_code', 'revoked_at', 'status', 'subscriber_id', 'updated_at', 'updated_by_ref', 'version']);

    $user = User::factory()->create();
    $this->actingAs($user);
    app(ToolConsentService::class)->grant($user->id, ToolCapability::TasksWrite, 0, ToolConsentReason::SubscriberRequest, EvidenceRef::of('message:1'));
    expect(DB::table('tool_consents')->count())->toBe(1);

    // The two F2 invocation migrations and the reminder-delivery one sit on top.
    Artisan::call('migrate:rollback', ['--step' => 4, '--force' => true]);

    expect(Schema::hasTable('tool_consents'))->toBeFalse()
        // Everything the previous phases created is still there.
        ->and(Schema::hasTable('finance_period_closes'))->toBeTrue()
        ->and(Schema::hasTable('fx_conversions'))->toBeTrue()
        ->and(Schema::hasTable('users'))->toBeTrue()
        ->and(Schema::hasTable('audit_logs'))->toBeTrue()
        ->and(DB::table('users')->count())->toBe(1)
        ->and(DB::table('migrations')->count())->toBe(59);

    Artisan::call('migrate', ['--force' => true]);

    expect(Schema::hasTable('tool_consents'))->toBeTrue()
        ->and(Schema::hasIndex('tool_consents', 'tool_consents_subscriber_capability_unique'))->toBeTrue()
        ->and(DB::table('tool_consents')->count())->toBe(0) // the table comes back empty, as a dropped table must
        ->and(DB::table('migrations')->count())->toBe(63);

    app(ToolConsentService::class)->grant($user->id, ToolCapability::TasksWrite, 0, ToolConsentReason::SubscriberRequest, EvidenceRef::of('message:2'));
    expect(DB::table('tool_consents')->count())->toBe(1);
});

it('enforces one consent row per (subscriber, capability) at the database level', function () {
    $user = User::factory()->create();
    $row = ['subscriber_id' => $user->id, 'capability' => 'tasks.write', 'status' => 'granted', 'granted_at' => now(), 'reason_code' => 'policy', 'version' => 1, 'updated_by_ref' => 'console', 'created_at' => now(), 'updated_at' => now()];

    DB::table('tool_consents')->insert($row);

    // Each failing insert runs inside its own savepoint: on PostgreSQL a failed statement would otherwise
    // abort the surrounding test transaction and every later assertion with it.
    expect(fn () => DB::transaction(fn () => DB::table('tool_consents')->insert($row)))->toThrow(UniqueConstraintViolationException::class)
        ->and(DB::table('tool_consents')->count())->toBe(1);

    // A different capability for the same subscriber is a different decision and is allowed.
    DB::table('tool_consents')->insert([...$row, 'capability' => 'memory.read']);
    expect(DB::table('tool_consents')->count())->toBe(2);
});

it('on PostgreSQL the status state machine is a database constraint, not only a service rule', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('CHECK constraints are asserted on PostgreSQL.');
    }

    $user = User::factory()->create();
    $base = ['subscriber_id' => $user->id, 'capability' => 'tasks.write', 'reason_code' => 'policy', 'version' => 1, 'updated_by_ref' => 'console', 'created_at' => now(), 'updated_at' => now()];

    $insert = static fn (array $row) => fn () => DB::transaction(fn () => DB::table('tool_consents')->insert($row));

    expect($insert([...$base, 'status' => 'maybe', 'granted_at' => now()]))->toThrow(QueryException::class)
        ->and($insert([...$base, 'status' => 'granted', 'granted_at' => null]))->toThrow(QueryException::class)
        ->and($insert([...$base, 'status' => 'revoked', 'revoked_at' => null]))->toThrow(QueryException::class)
        ->and($insert([...$base, 'status' => 'granted', 'granted_at' => now(), 'version' => 0]))->toThrow(QueryException::class)
        ->and(DB::table('tool_consents')->count())->toBe(0);
});
