<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E3 — a frozen REPORTING conversion of one subject (customer payment,
 * customer refund, cost reconciliation) into a target currency, APPEND-ONLY:
 * the exact fx_rate_id the finance user chose, the rate snapshot, the
 * direction (direct = multiply, inverse = divide, same rate row — no
 * reciprocal row), the subject's policy date and the rate date, the source
 * amount with its scale and the target amount rounded ONCE (half-up) at the
 * target scale. Never changes the subject; never a revenue figure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fx_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scope_id')->constrained('fx_conversion_scopes')->restrictOnDelete();
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id');
            $table->string('purpose', 24);
            $table->timestamp('subject_date', 6);
            $table->decimal('source_amount', 20, 6);
            $table->unsignedTinyInteger('source_scale');
            $table->string('source_currency', 3);
            $table->foreignId('fx_rate_id')->constrained('fx_rates')->restrictOnDelete();
            $table->date('fx_rate_date');
            $table->decimal('rate_snapshot', 24, 12);
            $table->string('direction', 8);
            $table->decimal('target_amount', 20, 6);
            $table->unsignedTinyInteger('target_scale');
            $table->string('target_currency', 3);
            $table->unsignedBigInteger('supersedes_id')->nullable();
            $table->string('reason_code', 32)->nullable();
            $table->string('actor_ref', 64);
            $table->timestamp('created_at', 6);

            $table->index(['subject_type', 'subject_id'], 'fx_conversions_subject_idx');
            $table->index('scope_id', 'fx_conversions_scope_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE fx_conversions ADD CONSTRAINT fx_conversions_direction_check CHECK (direction IN ('direct', 'inverse'))");
            DB::statement("ALTER TABLE fx_conversions ADD CONSTRAINT fx_conversions_purpose_check CHECK (purpose IN ('reporting'))");
            DB::statement('ALTER TABLE fx_conversions ADD CONSTRAINT fx_conversions_currencies_check CHECK (source_currency <> target_currency)');
            DB::statement('ALTER TABLE fx_conversions ADD CONSTRAINT fx_conversions_scales_check CHECK (source_scale <= 6 AND target_scale <= 6)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_conversions');
    }
};
