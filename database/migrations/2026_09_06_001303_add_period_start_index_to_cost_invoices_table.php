<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E5.2b — performance only, no data change. The cost invoices list
 * filters by a period_start month window that is allowed WITHOUT a component
 * or counterparty; the existing cost_invoices_scope_idx starts with
 * (component, counterparty_key) and cannot serve that path, so PostgreSQL
 * sequentially scans the table. This composite index serves the month-window
 * range and the stable id ordering of the 25-row pagination. Nothing else is
 * indexed (currency / issued_at stay as they are). Reversible: down drops the
 * index only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cost_invoices', function (Blueprint $table) {
            $table->index(['period_start', 'id'], 'cost_invoices_period_start_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('cost_invoices', function (Blueprint $table) {
            $table->dropIndex('cost_invoices_period_start_id_idx');
        });
    }
};
