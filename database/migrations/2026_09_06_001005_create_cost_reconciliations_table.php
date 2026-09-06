<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E2 — the reconciliation record, APPEND-ONLY: the amount finance
 * accepted as the actual cost of one scope, plus a frozen snapshot of what
 * the calculated ledger showed at that instant (known amount, priced /
 * unpriced / currency-mismatch rows, max event id, coverage, canonical hash).
 * Later ledger rows never change it; the query detects "ledger moved".
 *
 *  - source: invoice (evidence allocations, Σ = reconciled_amount) ·
 *    manual_evidenced · confirmed_zero (an explicit financial attestation,
 *    reconciled_amount = 0, reason + evidence + actor + typed confirmation).
 *  - supersedes_id: the reconciliation this one replaced (the scope pointer
 *    moved from it to this row). Corrections after the fact are
 *    cost_adjustments, never edits.
 *  - reconciled_amount is never an invoice total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scope_id')->constrained('cost_reconciliation_scopes')->restrictOnDelete();
            $table->string('component', 16);
            $table->string('counterparty_key', 64);
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->string('currency', 3);
            $table->string('source', 24);
            $table->decimal('reconciled_amount', 16, 6);
            $table->decimal('calculated_known_amount', 16, 6);
            $table->unsignedInteger('calculated_priced_rows');
            $table->unsignedInteger('unpriced_rows');
            $table->unsignedInteger('currency_mismatch_rows');
            $table->unsignedBigInteger('ledger_max_event_id')->nullable();
            $table->string('cost_coverage_status', 24);
            $table->timestamp('captured_at', 6);
            $table->string('snapshot_hash', 64);
            $table->unsignedBigInteger('supersedes_id')->nullable();
            $table->string('reason_code', 32)->nullable();
            $table->string('evidence_ref', 191)->nullable();
            $table->string('actor_ref', 64);
            $table->timestamp('created_at');

            $table->index(['scope_id', 'id'], 'cost_reconciliations_scope_idx');
            $table->index(['component', 'period_start'], 'cost_reconciliations_component_period_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE cost_reconciliations ADD CONSTRAINT cost_reconciliations_source_check CHECK (source IN ('invoice', 'manual_evidenced', 'confirmed_zero'))");
            DB::statement("ALTER TABLE cost_reconciliations ADD CONSTRAINT cost_reconciliations_zero_check CHECK (source <> 'confirmed_zero' OR reconciled_amount = 0)");
            DB::statement('ALTER TABLE cost_reconciliations ADD CONSTRAINT cost_reconciliations_period_check CHECK (period_end > period_start)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_reconciliations');
    }
};
