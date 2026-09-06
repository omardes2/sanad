<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E4 — APPEND-ONLY period close records. A `closed` row freezes the
 * seven cash-basis figures in the reporting currency (each NULL = NOT
 * AVAILABLE), every condition it checked, the canonical inputs snapshot and
 * its sha256 (input_hash — derived from the canonical JSON only). A
 * `reopened` row references the close it reopens (reopened_close_id) with a
 * mandatory reason and evidence; the old close is never edited. Revisions
 * chain through previous_close_id. Reconciled Cash Contribution is an
 * internal cash-basis metric — never gross profit, margin or revenue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_period_closes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scope_id')->constrained('finance_period_close_scopes')->restrictOnDelete();
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->string('reporting_currency', 3);
            $table->string('status', 8);
            $table->unsignedInteger('revision');
            $table->unsignedBigInteger('previous_close_id')->nullable();
            $table->unsignedBigInteger('reopened_close_id')->nullable();
            $table->string('idempotency_key', 191)->unique();
            $table->decimal('gross_cash_collected', 20, 6)->nullable();
            $table->decimal('refunds', 20, 6)->nullable();
            $table->decimal('net_cash', 20, 6)->nullable();
            $table->decimal('gateway_fees', 20, 6)->nullable();
            $table->decimal('net_cash_after_gateway_fees', 20, 6)->nullable();
            $table->decimal('reconciled_service_cost', 20, 6)->nullable();
            $table->decimal('reconciled_cash_contribution', 20, 6)->nullable();
            $table->json('conditions');
            $table->json('inputs_snapshot');
            $table->string('input_hash', 64)->nullable();
            $table->string('typed_confirmation', 32);
            $table->string('reason_code', 32)->nullable();
            $table->string('evidence_ref', 191)->nullable();
            $table->timestamp('closed_at', 6);
            $table->string('actor_ref', 64);
            $table->timestamp('created_at', 6);

            $table->index(['scope_id', 'id'], 'finance_period_closes_scope_idx');
            $table->index('period_start', 'finance_period_closes_period_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE finance_period_closes ADD CONSTRAINT finance_period_closes_status_check CHECK (status IN ('closed', 'reopened'))");
            DB::statement('ALTER TABLE finance_period_closes ADD CONSTRAINT finance_period_closes_period_check CHECK (period_end > period_start)');
            DB::statement("ALTER TABLE finance_period_closes ADD CONSTRAINT finance_period_closes_reopen_check CHECK (status <> 'reopened' OR (reopened_close_id IS NOT NULL AND reason_code IS NOT NULL AND evidence_ref IS NOT NULL))");
            DB::statement("ALTER TABLE finance_period_closes ADD CONSTRAINT finance_period_closes_hash_check CHECK (status <> 'closed' OR input_hash IS NOT NULL)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_period_closes');
    }
};
