<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B2 — link each ledger row to the price it was costed with.
 *
 * Additive and nullable: rows written before B2 keep NULLs (unknown pricing —
 * never back-filled, never guessed). Plain ids, NOT foreign keys, matching the
 * ledger's snapshot semantics from B1: a price or model row must never be able
 * to block or cascade into financial history.
 *
 *  - ai_model_id / model_price_id: what the cost was derived from.
 *  - pricing_snapshot: the exact rates used (independent of the price row).
 *  - cost_source: how provider_cost was obtained — `model_price`,
 *    `config_rate` (legacy B1 rates), or the UNKNOWN-cost markers `none` /
 *    `currency_mismatch`. A zero cost with an unknown source is NOT free; it
 *    is unpriced, and reports must count it as such.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usage_events', function (Blueprint $table) {
            $table->unsignedBigInteger('ai_model_id')->nullable()->after('model');
            $table->unsignedBigInteger('model_price_id')->nullable()->after('ai_model_id');
            $table->json('pricing_snapshot')->nullable()->after('total_cost');
            $table->string('cost_source')->nullable()->after('pricing_snapshot');

            $table->index('model_price_id', 'usage_events_model_price_idx');
            $table->index(['ai_model_id', 'occurred_at'], 'usage_events_model_occurred_idx');
            $table->index('cost_source', 'usage_events_cost_source_idx');
        });
    }

    public function down(): void
    {
        Schema::table('usage_events', function (Blueprint $table) {
            $table->dropIndex('usage_events_model_price_idx');
            $table->dropIndex('usage_events_model_occurred_idx');
            $table->dropIndex('usage_events_cost_source_idx');
            $table->dropColumn(['ai_model_id', 'model_price_id', 'pricing_snapshot', 'cost_source']);
        });
    }
};
