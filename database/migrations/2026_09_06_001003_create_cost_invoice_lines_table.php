<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E2 — signed invoice lines, append-only, added only while the invoice
 * is a draft (frozen at confirmation). Sign contract, enforced by the service
 * and by PostgreSQL:
 *      service >= 0 · tax >= 0 · other >= 0 · credit <= 0
 *      Σ signed line amounts = invoice total_amount (checked at confirmation)
 * Only `service` and `credit` lines can ever be allocated to a reconciliation;
 * tax and other never enter service cost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cost_invoice_id')->constrained('cost_invoices')->restrictOnDelete();
            $table->unsignedInteger('line_no');
            $table->string('kind', 16);
            $table->string('description_code', 32);
            $table->decimal('amount', 16, 6);
            $table->string('currency', 3);
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->string('actor_ref', 64);
            $table->timestamp('created_at');

            $table->unique(['cost_invoice_id', 'line_no'], 'cost_invoice_lines_invoice_line_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE cost_invoice_lines ADD CONSTRAINT cost_invoice_lines_kind_check CHECK (kind IN ('service', 'tax', 'credit', 'other'))");
            DB::statement("ALTER TABLE cost_invoice_lines ADD CONSTRAINT cost_invoice_lines_sign_check CHECK ((kind = 'credit' AND amount <= 0) OR (kind <> 'credit' AND amount >= 0))");
            DB::statement('ALTER TABLE cost_invoice_lines ADD CONSTRAINT cost_invoice_lines_period_check CHECK (period_start IS NULL OR period_end IS NULL OR period_end > period_start)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_invoice_lines');
    }
};
