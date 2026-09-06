<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * E5.2a — the ONE migration of the phase: a nullable idempotency_key with a
 * unique index on payment_allocations and refund_allocations. Reversible
 * (both tables survive the rollback, only the column and its index go),
 * re-appliable, no backfill: pre-existing rows keep NULL.
 */
it('adds and removes the allocation idempotency columns and unique indexes reversibly on both tables, without touching existing rows', function () {
    $files = glob(database_path('migrations/*.php'));
    expect($files)->toHaveCount(57)
        ->and(basename(end($files)))->toBe('2026_09_06_001301_add_idempotency_key_to_allocation_tables.php')
        ->and(Schema::hasColumn('payment_allocations', 'idempotency_key'))->toBeTrue()
        ->and(Schema::hasColumn('refund_allocations', 'idempotency_key'))->toBeTrue()
        ->and(Schema::hasIndex('payment_allocations', 'payment_allocations_idempotency_key_unique'))->toBeTrue()
        ->and(Schema::hasIndex('refund_allocations', 'refund_allocations_idempotency_key_unique'))->toBeTrue()
        ->and(collect(Schema::getColumns('payment_allocations'))->firstWhere('name', 'idempotency_key')['nullable'])->toBeTrue()
        ->and(collect(Schema::getColumns('refund_allocations'))->firstWhere('name', 'idempotency_key')['nullable'])->toBeTrue();

    Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);

    expect(Schema::hasTable('payment_allocations'))->toBeTrue()
        ->and(Schema::hasTable('refund_allocations'))->toBeTrue()
        ->and(Schema::hasColumn('payment_allocations', 'idempotency_key'))->toBeFalse()
        ->and(Schema::hasColumn('refund_allocations', 'idempotency_key'))->toBeFalse()
        ->and(Schema::hasIndex('payment_allocations', 'payment_allocations_idempotency_key_unique'))->toBeFalse()
        ->and(Schema::hasIndex('refund_allocations', 'refund_allocations_idempotency_key_unique'))->toBeFalse()
        ->and(Schema::hasIndex('payment_allocations', 'payment_allocations_payment_idx'))->toBeTrue() // the E1 indexes are untouched
        ->and(Schema::hasIndex('refund_allocations', 'refund_allocations_refund_idx'))->toBeTrue();

    Artisan::call('migrate', ['--force' => true]);

    expect(Schema::hasColumn('payment_allocations', 'idempotency_key'))->toBeTrue()
        ->and(Schema::hasColumn('refund_allocations', 'idempotency_key'))->toBeTrue()
        ->and(Schema::hasIndex('payment_allocations', 'payment_allocations_idempotency_key_unique'))->toBeTrue()
        ->and(Schema::hasIndex('refund_allocations', 'refund_allocations_idempotency_key_unique'))->toBeTrue()
        ->and(DB::table('migrations')->count())->toBe(57);
});
