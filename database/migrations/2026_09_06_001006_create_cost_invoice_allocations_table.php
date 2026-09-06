<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E2 — the many-to-many EVIDENCE relation: which part of which invoice
 * line supports which reconciliation. Append-only, signed: a service line
 * allocates a positive amount, a credit line a negative one (same sign as the
 * line, never the opposite); |Σ allocations of a line| ≤ |line amount| across
 * ALL reconciliations, checked under the invoice row lock — fully accepted or
 * fully refused, never clipped. No automatic proration: every monthly share
 * is an explicit allocation. Tax / other lines are never allocatable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_invoice_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cost_invoice_id')->constrained('cost_invoices')->restrictOnDelete();
            $table->foreignId('cost_invoice_line_id')->constrained('cost_invoice_lines')->restrictOnDelete();
            $table->foreignId('cost_reconciliation_id')->constrained('cost_reconciliations')->restrictOnDelete();
            $table->decimal('amount', 16, 6);
            $table->string('currency', 3);
            $table->string('actor_ref', 64);
            $table->timestamp('created_at');

            $table->index('cost_invoice_line_id', 'cost_invoice_allocations_line_idx');
            $table->index('cost_reconciliation_id', 'cost_invoice_allocations_reconciliation_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE cost_invoice_allocations ADD CONSTRAINT cost_invoice_allocations_nonzero_check CHECK (amount <> 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_invoice_allocations');
    }
};
