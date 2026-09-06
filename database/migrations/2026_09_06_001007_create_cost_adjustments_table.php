<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E2 — post-reconciliation corrections, append-only and signed. The
 * original reconciled_amount is never edited:
 *      Adjusted Reconciled Cost = Base Reconciled Amount + Σ adjustments.
 * Only the scope's CURRENT reconciliation accepts adjustments (under the
 * scope lock); reason + evidence are mandatory; every row is audited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cost_reconciliation_id')->constrained('cost_reconciliations')->restrictOnDelete();
            $table->decimal('amount', 16, 6);
            $table->string('currency', 3);
            $table->string('reason_code', 32);
            $table->string('evidence_ref', 191);
            $table->string('actor_ref', 64);
            $table->timestamp('created_at');

            $table->index('cost_reconciliation_id', 'cost_adjustments_reconciliation_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE cost_adjustments ADD CONSTRAINT cost_adjustments_nonzero_check CHECK (amount <> 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_adjustments');
    }
};
