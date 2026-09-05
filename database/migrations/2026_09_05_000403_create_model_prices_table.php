<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B2 — HISTORICAL model prices (append-only).
 *
 * A price is a period [effective_from, effective_until) — the start is
 * inclusive, the end exclusive, and NULL end = open (current). Changing a price
 * never edits an existing row: PriceBook closes the open period and inserts a
 * new one. Every usage event stores the id of the price it was costed with plus
 * a snapshot of the rates, so a later price change can never alter old costs.
 *
 * Rates are per MILLION tokens (the vendors' own unit) with 8 decimals;
 * `per_request` is a flat amount added per invocation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('model_id')->constrained('ai_models')->restrictOnDelete();
            $table->string('currency', 3);
            // token | request | minute | image (only token is costed in B2).
            $table->string('unit')->default('token');
            $table->decimal('input_per_million', 14, 8)->default(0);
            $table->decimal('output_per_million', 14, 8)->default(0);
            $table->decimal('cached_input_per_million', 14, 8)->nullable();
            $table->decimal('per_request', 14, 8)->default(0);
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            // manual | import | seed
            $table->string('source')->default('manual');
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['model_id', 'effective_from'], 'model_prices_model_from_idx');
        });

        // Exactly one OPEN period per model — the database-level backstop behind
        // PriceBook's parent-row lock (works on PostgreSQL and SQLite).
        DB::statement('CREATE UNIQUE INDEX model_prices_one_open_per_model ON model_prices (model_id) WHERE effective_until IS NULL');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE model_prices ADD CONSTRAINT model_prices_period_check CHECK (effective_until IS NULL OR effective_until > effective_from)');
            DB::statement('ALTER TABLE model_prices ADD CONSTRAINT model_prices_non_negative_check CHECK (input_per_million >= 0 AND output_per_million >= 0 AND per_request >= 0 AND (cached_input_per_million IS NULL OR cached_input_per_million >= 0))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('model_prices');
    }
};
