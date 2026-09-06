<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E2 — the reconciliation SCOPE projection: one row per
 * (component, counterparty_key, calendar month UTC, currency). It carries only
 * the pointer to the current reconciliation (+ a version) and is the row the
 * service locks (FOR UPDATE) for every reconciliation and adjustment — which
 * also gives communication / external components a lock target without any
 * provider row. Updated only by the service under the lock with an audit
 * entry; every other column is fixed at creation. History lives in the
 * append-only cost_reconciliations table.
 *
 * Period contract in E2: [first day 00:00:00 UTC, first day of next month).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_reconciliation_scopes', function (Blueprint $table) {
            $table->id();
            $table->string('component', 16);
            $table->string('counterparty_key', 64);
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->string('currency', 3);
            $table->unsignedBigInteger('current_reconciliation_id')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->string('updated_by_ref', 64)->nullable();
            $table->timestamps();

            $table->unique(['component', 'counterparty_key', 'period_start', 'currency'], 'cost_reconciliation_scopes_scope_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE cost_reconciliation_scopes ADD CONSTRAINT cost_reconciliation_scopes_component_check CHECK (component IN ('provider', 'communication', 'external'))");
            DB::statement('ALTER TABLE cost_reconciliation_scopes ADD CONSTRAINT cost_reconciliation_scopes_period_check CHECK (period_end > period_start)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_reconciliation_scopes');
    }
};
