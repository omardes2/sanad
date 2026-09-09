<?php

declare(strict_types=1);

use App\Enums\MemoryCategory;
use App\Enums\MemoryProvenance;
use App\Models\Memory;
use App\Models\User;
use App\Support\Memory\MemoryFingerprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Durable personal memory ships exactly ONE migration, and it is additive: two
 * columns and two indexes on a table that has existed since Sprint 0, and
 * nothing in E0–E5, F1–F4 or the reminder-delivery phase touched.
 */
it('is the 64th and last migration, adds only the two memory columns, and rolls back and forward cleanly', function () {
    $files = glob(database_path('migrations/*.php'));

    expect($files)->toHaveCount(64)
        ->and(basename($files[63]))->toBe('2026_09_10_000101_add_memory_fingerprint_and_provenance.php')
        ->and(Schema::hasColumn('memories', 'fingerprint'))->toBeTrue()
        ->and(Schema::hasColumn('memories', 'provenance'))->toBeTrue()
        ->and(Schema::hasIndex('memories', 'memories_user_category_fingerprint_unique'))->toBeTrue()
        ->and(Schema::hasIndex('memories', 'memories_user_active_importance_idx'))->toBeTrue();

    // The Sprint-0 columns this phase finally gives a writer are untouched.
    $columns = collect(Schema::getColumns('memories'))->pluck('name')->all();
    sort($columns);
    expect($columns)->toBe([
        'archived_at', 'category', 'content', 'created_at', 'fingerprint', 'id',
        'importance', 'metadata', 'provenance', 'source_message_id', 'updated_at', 'user_id',
    ]);

    $user = User::factory()->create();
    Memory::factory()->create(['user_id' => $user->id, 'content' => 'ذاكرة قبل التراجع']);

    Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);

    expect(Schema::hasColumn('memories', 'fingerprint'))->toBeFalse()
        ->and(Schema::hasColumn('memories', 'provenance'))->toBeFalse()
        // Every earlier phase survives, and so does the memory itself.
        ->and(Schema::hasTable('memories'))->toBeTrue()
        ->and(Schema::hasTable('reminders'))->toBeTrue()
        ->and(Schema::hasTable('tool_invocations'))->toBeTrue()
        ->and(Schema::hasTable('finance_period_closes'))->toBeTrue()
        ->and(Schema::hasColumn('reminders', 'claim_token'))->toBeTrue()
        ->and(DB::table('memories')->count())->toBe(1)
        ->and(DB::table('migrations')->count())->toBe(63);

    Artisan::call('migrate', ['--force' => true]);

    expect(Schema::hasColumn('memories', 'fingerprint'))->toBeTrue()
        ->and(Schema::hasIndex('memories', 'memories_user_category_fingerprint_unique'))->toBeTrue()
        ->and(DB::table('memories')->count())->toBe(1)
        // A row that pre-dates the columns gets the default provenance and no
        // fingerprint — which is exactly "no active duplicate slot claimed".
        ->and(DB::table('memories')->value('provenance'))->toBe(MemoryProvenance::Explicit->value)
        ->and(DB::table('migrations')->count())->toBe(64);
});

it('lets the DATABASE decide that one memory is one memory, per subscriber and per category', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $row = [
        'user_id' => $user->id,
        'category' => MemoryCategory::Preference->value,
        'content' => 'sealed',
        'fingerprint' => MemoryFingerprint::of('بحب القهوة سادة'),
        'importance' => 3,
        'provenance' => MemoryProvenance::Explicit->value,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('memories')->insert($row);

    // Not a service rule: the database itself refuses the second one. The
    // duplicate runs in a SAVEPOINT so PostgreSQL's aborted-transaction state
    // rolls back with it and the test can continue.
    expect(fn () => DB::transaction(fn () => DB::table('memories')->insert($row)))
        ->toThrow(UniqueConstraintViolationException::class);

    // Another subscriber, and another category, are different memories.
    DB::table('memories')->insert(array_merge($row, ['user_id' => $other->id]));
    DB::table('memories')->insert(array_merge($row, ['category' => MemoryCategory::Fact->value]));

    // And NULL is unconstrained, which is what lets archived rows keep their
    // content without holding the slot.
    DB::table('memories')->insert(array_merge($row, ['fingerprint' => null, 'archived_at' => now()]));
    DB::table('memories')->insert(array_merge($row, ['fingerprint' => null, 'archived_at' => now()]));

    expect(DB::table('memories')->count())->toBe(5)
        ->and(DB::table('memories')->whereNull('fingerprint')->count())->toBe(2);
});
