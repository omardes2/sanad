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
    // Undo the two D1 migrations (finance_mrr_snapshots, finance indexes), the
    // two C3 migrations (provider_health_checks, provider_credentials), the C1
    // migration (app_settings), the two C0 migrations (audit context, permission
    // tables), the four B2 migrations (pricing refs, model_prices, ai_models,
    // ai_providers) and the two B1 migrations (usage_charges, ledger).
    Artisan::call('migrate:rollback', ['--step' => 13, '--force' => true]);

    expect(Schema::hasTable('finance_mrr_snapshots'))->toBeFalse()
        ->and(Schema::hasIndex('usage_events', 'usage_events_occurred_idx'))->toBeFalse()
        ->and(Schema::hasTable('provider_health_checks'))->toBeFalse()
        ->and(Schema::hasTable('provider_credentials'))->toBeFalse()
        ->and(Schema::hasColumn('usage_events', 'total_cost'))->toBeFalse()
        ->and(Schema::hasColumn('usage_events', 'cost_source'))->toBeFalse()
        ->and(Schema::hasTable('usage_charges'))->toBeFalse()
        ->and(Schema::hasTable('model_prices'))->toBeFalse()
        ->and(Schema::hasTable('ai_models'))->toBeFalse()
        ->and(Schema::hasTable('ai_providers'))->toBeFalse()
        ->and(Schema::hasTable('permissions'))->toBeFalse()
        ->and(Schema::hasColumn('audit_logs', 'actor'))->toBeFalse()
        ->and(Schema::hasTable('app_settings'))->toBeFalse();

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
        // B2 pricing refs stay unknown for legacy rows — never back-filled or guessed.
        ->and($row->ai_model_id)->toBeNull()
        ->and($row->model_price_id)->toBeNull()
        ->and($row->pricing_snapshot)->toBeNull()
        ->and($row->cost_source)->toBeNull()
        ->and(Schema::hasTable('usage_charges'))->toBeTrue()
        ->and(Schema::hasTable('ai_providers'))->toBeTrue()
        ->and(Schema::hasTable('ai_models'))->toBeTrue()
        ->and(Schema::hasTable('model_prices'))->toBeTrue()
        ->and(Schema::hasTable('permissions'))->toBeTrue()
        ->and(Schema::hasColumn('audit_logs', 'actor'))->toBeTrue()
        ->and(Schema::hasTable('app_settings'))->toBeTrue()
        ->and(Schema::hasTable('finance_mrr_snapshots'))->toBeTrue()
        ->and(Schema::hasIndex('usage_events', 'usage_events_occurred_idx'))->toBeTrue()
        ->and(Schema::hasIndex('usage_events', 'usage_events_plan_occurred_idx'))->toBeTrue()
        ->and(Schema::hasIndex('usage_events', 'usage_events_provider_model_occurred_idx'))->toBeTrue();
});
