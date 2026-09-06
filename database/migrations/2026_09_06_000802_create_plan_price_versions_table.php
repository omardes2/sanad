<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E0 — plan price history with the same strictness as model_prices:
 * append-only versions over half-open periods [effective_from, effective_until).
 *
 *  - A financial change (price / currency / billing_period) closes the open
 *    version at the change instant and opens a new one, in ONE transaction
 *    under the parent plan row lock (PlanPriceBook). Name/description changes
 *    create nothing.
 *  - The only write to an existing row is closing it (effective_until NULL →
 *    instant), exactly once. Versions are never rewritten, split or back-dated;
 *    the first version of a pre-existing plan starts at the BASELINE capture
 *    instant, never earlier.
 *  - plan_id restricts deletion: a plan with price history cannot vanish.
 *  - The partial unique index is the database-level backstop for "exactly one
 *    open version per plan" (PostgreSQL and SQLite).
 *  - effective_from / effective_until carry MICROSECOND precision (timestamp(6)
 *    on PostgreSQL; the model writes "Y-m-d H:i:s.u" on both engines), so an
 *    admin can retry immediately after a stale conflict without any artificial
 *    spacing, and the PostgreSQL check keeps every closed period strictly
 *    positive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_price_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $table->decimal('price', 10, 2);
            $table->string('currency', 3);
            $table->string('billing_period', 16);
            // Microsecond precision: two consecutive versions are never forced
            // apart by a one-second clock, and [from, until) can never collapse
            // to a zero-length interval by timestamp rounding.
            $table->timestamp('effective_from', 6);
            $table->timestamp('effective_until', 6)->nullable();
            // baseline | admin
            $table->string('source', 16);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['plan_id', 'effective_from'], 'plan_price_versions_plan_from_idx');
        });

        DB::statement('CREATE UNIQUE INDEX plan_price_versions_one_open_per_plan ON plan_price_versions (plan_id) WHERE effective_until IS NULL');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE plan_price_versions ADD CONSTRAINT plan_price_versions_period_check CHECK (effective_until IS NULL OR effective_until > effective_from)');
            DB::statement('ALTER TABLE plan_price_versions ADD CONSTRAINT plan_price_versions_non_negative_check CHECK (price >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_price_versions');
    }
};
