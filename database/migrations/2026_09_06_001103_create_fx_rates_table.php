<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E3 — manual FX quotes, APPEND-ONLY: one row per revision of
 * (pair, rate_date). `rate` (scale 12, > 0) means 1 BASE = rate × QUOTE in
 * the pair's official orientation (snapshotted here). A correction is a new
 * revision with supersedes_id; nothing is ever updated or deleted. No
 * effective_from / effective_until: a quote never "stays valid" for later
 * dates, so no historical record can silently pick up a later rate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fx_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fx_pair_id')->constrained('fx_pairs')->restrictOnDelete();
            $table->foreignId('scope_id')->constrained('fx_rate_scopes')->restrictOnDelete();
            $table->date('rate_date');
            $table->string('base_currency', 3);
            $table->string('quote_currency', 3);
            $table->decimal('rate', 24, 12);
            $table->string('source', 16);
            $table->string('evidence_ref', 191);
            $table->string('reason_code', 32)->nullable();
            $table->unsignedBigInteger('supersedes_id')->nullable();
            $table->string('recorded_by_ref', 64);
            $table->timestamp('created_at', 6);

            $table->index(['fx_pair_id', 'rate_date'], 'fx_rates_pair_date_idx');
            $table->index('scope_id', 'fx_rates_scope_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE fx_rates ADD CONSTRAINT fx_rates_positive_check CHECK (rate > 0)');
            DB::statement("ALTER TABLE fx_rates ADD CONSTRAINT fx_rates_source_check CHECK (source IN ('manual'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_rates');
    }
};
