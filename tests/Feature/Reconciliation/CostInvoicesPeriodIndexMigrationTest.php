<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * E5.2b — the second (performance-only) migration of the phase: the
 * (period_start, id) index on cost_invoices that serves the month-window
 * list path and its id-ordered pagination. Reversible: down drops the index
 * only; the table, its rows and every E2 index stay intact; re-up succeeds.
 */
it('adds and removes cost_invoices_period_start_id_idx reversibly without touching the table, its rows or the other E2 indexes', function () {
    $files = glob(database_path('migrations/*.php'));
    $e2Indexes = ['cost_invoices_scope_idx', 'cost_invoices_status_idx', 'cost_invoices_counterparty_ref_unique', 'cost_invoices_idempotency_key_unique'];
    $invoice = e2Invoice();

    expect($files)->toHaveCount(59)
        ->and(basename(end($files)))->toBe('2026_09_06_001303_add_period_start_index_to_cost_invoices_table.php')
        ->and(Schema::hasIndex('cost_invoices', 'cost_invoices_period_start_id_idx'))->toBeTrue()
        ->and(Schema::hasIndex('cost_invoices', ['period_start', 'id']))->toBeTrue();

    Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);

    expect(Schema::hasTable('cost_invoices'))->toBeTrue()
        ->and(Schema::hasIndex('cost_invoices', 'cost_invoices_period_start_id_idx'))->toBeFalse()
        ->and(DB::table('cost_invoices')->where('id', $invoice->id)->exists())->toBeTrue() // rows untouched
        ->and(Schema::hasColumn('cost_adjustments', 'idempotency_key'))->toBeTrue(); // the first E5.2b migration is untouched
    foreach ($e2Indexes as $index) {
        expect(Schema::hasIndex('cost_invoices', $index))->toBeTrue($index);
    }

    Artisan::call('migrate', ['--force' => true]);

    expect(Schema::hasIndex('cost_invoices', 'cost_invoices_period_start_id_idx'))->toBeTrue()
        ->and(DB::table('migrations')->count())->toBe(59)
        ->and(DB::table('cost_invoices')->count())->toBe(1);
    foreach ($e2Indexes as $index) {
        expect(Schema::hasIndex('cost_invoices', $index))->toBeTrue($index);
    }
});
