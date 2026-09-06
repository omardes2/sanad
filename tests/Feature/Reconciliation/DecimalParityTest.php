<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Phase E2 — the same six-decimal sums on SQLite and PostgreSQL: line sums,
 * evidence caps and the ledger snapshot are integer arithmetic inside SQL.
 */
it('sums ledger, lines and allocations exactly at scale 6 on the driver in use', function () {
    foreach (['0.000001', '0.000002', '0.1', '0.2', '0.7', '1.000003'] as $i => $cost) {
        financeRow(['provider_cost' => $cost, 'total_cost' => $cost, 'occurred_at' => CarbonImmutable::parse('2026-08-0'.($i + 1), 'UTC')]);
    }
    $inv = e2ConfirmedInvoice(['service' => '0.300000', 'service2' => '1.700006', 'credit' => '-0.000006']); // 2.000000 total
    $lines = $inv->lines()->orderBy('line_no')->get();

    $rec = e2Reconcile([[$lines[0]->id, '0.300000'], [$lines[1]->id, '1.700006'], [$lines[2]->id, '-0.000006']]);

    expect(in_array(DB::connection()->getDriverName(), ['sqlite', 'pgsql'], true))->toBeTrue()
        ->and((string) $inv->fresh()->total_amount)->toBe('2.000000')
        ->and((string) $rec->reconciled_amount)->toBe('2.000000')
        ->and((string) $rec->calculated_known_amount)->toBe('2.000006') // no 2.0000059999
        ->and(e2Rule(fn () => e2Reconcile([[$lines[1]->id, '0.000001']], ['month' => '2026-09'])))->toBe('allocation_limit');
});
