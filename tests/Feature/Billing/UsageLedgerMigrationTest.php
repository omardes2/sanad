<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * The ledger migration must not assume usage_events is empty in production:
 * rows written by the pre-B1 engine must stay valid and get their derived
 * columns back-filled by the migration itself (no separate command).
 */
it('back-fills derived ledger columns for rows that pre-date the ledger and stays reversible', function () {
    // Undo the two B1 migrations (usage_charges, then the ledger extension).
    Artisan::call('migrate:rollback', ['--step' => 2, '--force' => true]);

    expect(Schema::hasColumn('usage_events', 'total_cost'))->toBeFalse()
        ->and(Schema::hasTable('usage_charges'))->toBeFalse();

    $owner = User::factory()->create();

    // A legacy row, exactly as the old engine wrote it (plus one still owned).
    DB::table('usage_events')->insert([
        'user_id' => $owner->id,
        'type' => 'ai_reply',
        'idempotency_key' => 'legacy-1',
        'provider' => 'groq',
        'model' => 'llama-3.3-70b-versatile',
        'input_units' => 10,
        'output_units' => 5,
        'quantity' => 1,
        'cost' => 0.25,
        'currency' => 'USD',
        'metadata' => null,
        'created_at' => '2026-09-01 10:00:00',
        'updated_at' => '2026-09-01 10:00:00',
    ]);

    Artisan::call('migrate', ['--force' => true]);

    $row = DB::table('usage_events')->where('idempotency_key', 'legacy-1')->first();

    expect((float) $row->total_cost)->toBe(0.25) // legacy cost = the total
        ->and((float) $row->provider_cost)->toBe(0.0) // never re-classified as provider cost
        ->and((float) $row->communication_cost)->toBe(0.0)
        ->and((float) $row->cost)->toBe(0.25)
        ->and($row->occurred_at)->toStartWith('2026-09-01 10:00:00')
        ->and($row->operation)->toBeNull() // unknown for legacy rows — never guessed
        ->and($row->outcome)->toBeNull() // unknown — never assumed succeeded
        ->and((int) $row->subscriber_id)->toBe($owner->id) // attribution snapshot back-filled
        ->and($row->subscription_id)->toBeNull()
        ->and(Schema::hasTable('usage_charges'))->toBeTrue();
});
