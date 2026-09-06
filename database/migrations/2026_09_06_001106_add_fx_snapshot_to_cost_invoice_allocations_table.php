<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E3 — allocation-level FX for reconciliation evidence (additive).
 * Every cost_invoice_allocation now freezes its OWN conversion:
 *  - source_amount / source_currency: the share in the invoice line's
 *    currency (the line cap is enforced on this column);
 *  - amount stays the value in the reconciliation scope currency;
 *  - fx_rate_id / fx_rate_snapshot / fx_direction / fx_rate_date: NULL for a
 *    NATIVE allocation (same currency), the exact chosen quote otherwise.
 * No conversion of a whole invoice; no latest-rate lookup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cost_invoice_allocations', function (Blueprint $table) {
            $table->decimal('source_amount', 16, 6)->nullable()->after('amount');
            $table->string('source_currency', 3)->nullable()->after('source_amount');
            $table->unsignedBigInteger('fx_rate_id')->nullable()->after('currency');
            $table->decimal('fx_rate_snapshot', 24, 12)->nullable()->after('fx_rate_id');
            $table->string('fx_direction', 8)->nullable()->after('fx_rate_snapshot');
            $table->date('fx_rate_date')->nullable()->after('fx_direction');
        });

        // The FK is declared on PostgreSQL (production); SQLite cannot drop a named
        // foreign key later, and the service is the only writer of fx_rate_id.
        if (DB::getDriverName() === 'pgsql') {
            Schema::table('cost_invoice_allocations', function (Blueprint $table) {
                $table->foreign('fx_rate_id', 'cost_invoice_allocations_fx_rate_fk')->references('id')->on('fx_rates')->restrictOnDelete();
            });
        }

        // Rows written before E3 were all native (E2 refused cross-currency evidence).
        DB::table('cost_invoice_allocations')->whereNull('source_amount')->update(['source_amount' => DB::raw('amount'), 'source_currency' => DB::raw('currency')]);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE cost_invoice_allocations ADD CONSTRAINT cost_invoice_allocations_fx_check CHECK ((fx_rate_id IS NULL AND fx_rate_snapshot IS NULL AND fx_direction IS NULL AND fx_rate_date IS NULL AND source_currency = currency) OR (fx_rate_id IS NOT NULL AND fx_rate_snapshot IS NOT NULL AND fx_direction IN ('direct', 'inverse') AND fx_rate_date IS NOT NULL AND source_currency <> currency))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE cost_invoice_allocations DROP CONSTRAINT IF EXISTS cost_invoice_allocations_fx_check');
            Schema::table('cost_invoice_allocations', function (Blueprint $table) {
                $table->dropForeign('cost_invoice_allocations_fx_rate_fk');
            });
        }

        Schema::table('cost_invoice_allocations', function (Blueprint $table) {
            $table->dropColumn(['source_amount', 'source_currency', 'fx_rate_id', 'fx_rate_snapshot', 'fx_direction', 'fx_rate_date']);
        });
    }
};
