<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E4 — the immutable drill-down PROJECTION of a close's canonical
 * inputs snapshot: one row per input (payment, refund, gateway fee,
 * reconciliation, adjustment) generated from the SAME canonical JSON inside
 * the same atomic close transaction. Never a source of truth on its own,
 * never updated, never deleted; input_hash comes from the JSON only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_period_close_inputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('close_id')->constrained('finance_period_closes')->restrictOnDelete();
            $table->string('input_type', 24);
            $table->unsignedBigInteger('input_id');
            $table->decimal('amount', 20, 6);
            $table->string('currency', 3);
            $table->unsignedTinyInteger('scale');
            $table->decimal('reporting_amount', 20, 6)->nullable();
            $table->string('reporting_currency', 3);
            $table->string('status', 24);
            $table->unsignedBigInteger('fx_conversion_id')->nullable();
            $table->unsignedBigInteger('fx_rate_id')->nullable();
            $table->decimal('fx_rate_snapshot', 24, 12)->nullable();
            $table->string('fx_direction', 8)->nullable();
            $table->json('flags');
            $table->timestamp('created_at', 6);

            $table->index(['close_id', 'input_type'], 'finance_period_close_inputs_close_idx');
            $table->unique(['close_id', 'input_type', 'input_id'], 'finance_period_close_inputs_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE finance_period_close_inputs ADD CONSTRAINT finance_period_close_inputs_type_check CHECK (input_type IN ('payment', 'refund', 'gateway_fee', 'reconciliation', 'adjustment'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_period_close_inputs');
    }
};
