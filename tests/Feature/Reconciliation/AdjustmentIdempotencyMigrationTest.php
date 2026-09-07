<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/** E5.2b — the first of the two migrations of the phase: nullable idempotency_key + unique index on cost_adjustments, reversible, no backfill. */
it('adds and removes cost_adjustments.idempotency_key and its unique index reversibly without touching the table or its rows', function () {
    $files = glob(database_path('migrations/*.php'));
    expect($files)->toHaveCount(59)
        ->and(basename($files[count($files) - 2]))->toBe('2026_09_06_001302_add_idempotency_key_to_cost_adjustments_table.php')
        ->and(Schema::hasColumn('cost_adjustments', 'idempotency_key'))->toBeTrue()
        ->and(Schema::hasIndex('cost_adjustments', 'cost_adjustments_idempotency_key_unique'))->toBeTrue()
        ->and(collect(Schema::getColumns('cost_adjustments'))->firstWhere('name', 'idempotency_key')['nullable'])->toBeTrue();

    Artisan::call('migrate:rollback', ['--step' => 2, '--force' => true]); // the period index migration sits on top of this one

    expect(Schema::hasTable('cost_adjustments'))->toBeTrue()
        ->and(Schema::hasColumn('cost_adjustments', 'idempotency_key'))->toBeFalse()
        ->and(Schema::hasIndex('cost_adjustments', 'cost_adjustments_idempotency_key_unique'))->toBeFalse()
        ->and(Schema::hasIndex('cost_adjustments', 'cost_adjustments_reconciliation_idx'))->toBeTrue()
        ->and(Schema::hasColumn('payment_allocations', 'idempotency_key'))->toBeTrue(); // the E5.2a migration is untouched

    Artisan::call('migrate', ['--force' => true]);

    expect(Schema::hasColumn('cost_adjustments', 'idempotency_key'))->toBeTrue()
        ->and(Schema::hasIndex('cost_adjustments', 'cost_adjustments_idempotency_key_unique'))->toBeTrue()
        ->and(DB::table('migrations')->count())->toBe(59);
});
