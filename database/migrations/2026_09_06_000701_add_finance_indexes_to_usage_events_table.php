<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase D1 — indexes for the financial aggregations over the ledger.
 *
 * Every finance query is bounded by an occurred_at window and then grouped by
 * plan or by provider/model; the B1/B2 indexes cover subscriber, model-id and
 * operation lookups but not a plain window scan nor those two groupings.
 * Indexes only: no column, no data and no semantic change to any row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usage_events', function (Blueprint $table) {
            $table->index('occurred_at', 'usage_events_occurred_idx');
            $table->index(['plan_id', 'occurred_at'], 'usage_events_plan_occurred_idx');
            $table->index(['provider', 'model', 'occurred_at'], 'usage_events_provider_model_occurred_idx');
        });
    }

    public function down(): void
    {
        Schema::table('usage_events', function (Blueprint $table) {
            $table->dropIndex('usage_events_occurred_idx');
            $table->dropIndex('usage_events_plan_occurred_idx');
            $table->dropIndex('usage_events_provider_model_occurred_idx');
        });
    }
};
