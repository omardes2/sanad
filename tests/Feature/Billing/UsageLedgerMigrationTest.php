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
    // Undo the two E5.2b migrations (period_start index on cost_invoices, idempotency_key on cost_adjustments),
    // the E5.2a migration (idempotency_key on payment_allocations / refund_allocations),
    // the three E4 migrations (finance_period_close_inputs, finance_period_closes,
    // finance_period_close_scopes), the six E3 migrations (fx snapshot columns on cost_invoice_allocations,
    // fx_conversions, fx_conversion_scopes, fx_rates, fx_rate_scopes, fx_pairs),
    // the seven E2 migrations (cost_adjustments, cost_invoice_allocations,
    // cost_reconciliations, cost_reconciliation_scopes, cost_invoice_lines,
    // cost_invoice_events, cost_invoices), the five E1 migrations (refund_allocations, payment_allocations,
    // customer_refunds, customer_payment_events, customer_payments), the
    // two E0 migrations (plan_price_versions, subscription_events), the
    // two D1 migrations (finance_mrr_snapshots, finance indexes), the
    // two C3 migrations (provider_health_checks, provider_credentials), the C1
    // migration (app_settings), the two C0 migrations (audit context, permission
    // tables), the four B2 migrations (pricing refs, model_prices, ai_models,
    // ai_providers) and the two B1 migrations (usage_charges, ledger).
    Artisan::call('migrate:rollback', ['--step' => 39, '--force' => true]);

    expect(Schema::hasTable('finance_period_close_inputs'))->toBeFalse()
        ->and(Schema::hasTable('finance_period_closes'))->toBeFalse()
        ->and(Schema::hasTable('finance_period_close_scopes'))->toBeFalse()
        ->and(Schema::hasTable('fx_conversions'))->toBeFalse()
        ->and(Schema::hasTable('fx_conversion_scopes'))->toBeFalse()
        ->and(Schema::hasTable('fx_rates'))->toBeFalse()
        ->and(Schema::hasTable('fx_rate_scopes'))->toBeFalse()
        ->and(Schema::hasTable('fx_pairs'))->toBeFalse()
        ->and(Schema::hasTable('cost_adjustments'))->toBeFalse()
        ->and(Schema::hasTable('cost_invoice_allocations'))->toBeFalse()
        ->and(Schema::hasTable('cost_reconciliations'))->toBeFalse()
        ->and(Schema::hasTable('cost_reconciliation_scopes'))->toBeFalse()
        ->and(Schema::hasTable('cost_invoice_lines'))->toBeFalse()
        ->and(Schema::hasTable('cost_invoice_events'))->toBeFalse()
        ->and(Schema::hasTable('cost_invoices'))->toBeFalse()
        ->and(Schema::hasTable('refund_allocations'))->toBeFalse()
        ->and(Schema::hasTable('payment_allocations'))->toBeFalse()
        ->and(Schema::hasTable('customer_refunds'))->toBeFalse()
        ->and(Schema::hasTable('customer_payment_events'))->toBeFalse()
        ->and(Schema::hasTable('customer_payments'))->toBeFalse()
        ->and(Schema::hasTable('plan_price_versions'))->toBeFalse()
        ->and(Schema::hasTable('subscription_events'))->toBeFalse()
        ->and(Schema::hasTable('finance_mrr_snapshots'))->toBeFalse()
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
        ->and(Schema::hasTable('subscription_events'))->toBeTrue()
        ->and(Schema::hasTable('plan_price_versions'))->toBeTrue()
        ->and(Schema::hasTable('customer_payments'))->toBeTrue()
        ->and(Schema::hasTable('customer_payment_events'))->toBeTrue()
        ->and(Schema::hasTable('customer_refunds'))->toBeTrue()
        ->and(Schema::hasTable('payment_allocations'))->toBeTrue()
        ->and(Schema::hasTable('refund_allocations'))->toBeTrue()
        ->and(Schema::hasTable('cost_invoices'))->toBeTrue()
        ->and(Schema::hasTable('cost_invoice_events'))->toBeTrue()
        ->and(Schema::hasTable('cost_invoice_lines'))->toBeTrue()
        ->and(Schema::hasTable('cost_reconciliation_scopes'))->toBeTrue()
        ->and(Schema::hasTable('cost_reconciliations'))->toBeTrue()
        ->and(Schema::hasTable('cost_invoice_allocations'))->toBeTrue()
        ->and(Schema::hasTable('cost_adjustments'))->toBeTrue()
        ->and(Schema::hasTable('fx_pairs'))->toBeTrue()
        ->and(Schema::hasTable('fx_rate_scopes'))->toBeTrue()
        ->and(Schema::hasTable('fx_rates'))->toBeTrue()
        ->and(Schema::hasTable('fx_conversion_scopes'))->toBeTrue()
        ->and(Schema::hasTable('fx_conversions'))->toBeTrue()
        ->and(Schema::hasTable('finance_period_close_scopes'))->toBeTrue()
        ->and(Schema::hasTable('finance_period_closes'))->toBeTrue()
        ->and(Schema::hasTable('finance_period_close_inputs'))->toBeTrue()
        ->and(Schema::hasColumns('cost_invoice_allocations', ['source_amount', 'source_currency', 'fx_rate_id', 'fx_rate_snapshot', 'fx_direction', 'fx_rate_date']))->toBeTrue()
        ->and(Schema::hasIndex('usage_events', 'usage_events_occurred_idx'))->toBeTrue()
        ->and(Schema::hasIndex('usage_events', 'usage_events_plan_occurred_idx'))->toBeTrue()
        ->and(Schema::hasIndex('usage_events', 'usage_events_provider_model_occurred_idx'))->toBeTrue();
});
