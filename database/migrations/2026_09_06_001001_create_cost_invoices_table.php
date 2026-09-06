<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E2 — a supplier invoice as EVIDENCE for one cost component
 * (provider / communication / external), never as cost truth: confirming an
 * invoice makes nothing "actual" — only an explicit cost reconciliation does.
 *
 *  - counterparty_key: a stable bounded key (an AI provider key for the
 *    provider component, a communication / external supplier key otherwise);
 *    no vendor CRM, no names, no addresses, no PII.
 *  - (counterparty_key, invoice_ref) unique when a real reference exists;
 *    idempotency_key mandatory and unique. Several invoices may cover the
 *    same counterparty and period (partial invoices) — no uniqueness there.
 *  - period_start / period_end: the invoice's OWN coverage as printed on it;
 *    reconciliation months are attributed through explicit line allocations.
 *  - total_amount is the full signed document total (taxes, credits and other
 *    services included) at the ledger scale (6). Lines must add up to it at
 *    confirmation; taxes / other never become service cost, credits are
 *    negative evidence.
 *  - current_status / latest_event_id: a projection of cost_invoice_events,
 *    moved only by the service under FOR UPDATE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('component', 16);
            $table->string('counterparty_key', 64);
            $table->string('invoice_ref', 191)->nullable();
            $table->string('idempotency_key', 191)->unique();
            $table->timestamp('issued_at');
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->string('currency', 3);
            $table->decimal('total_amount', 16, 6);
            $table->string('evidence_ref', 191)->nullable();
            $table->string('current_status', 16);
            $table->unsignedBigInteger('latest_event_id')->nullable();
            $table->unsignedBigInteger('superseded_by_id')->nullable();
            $table->string('recorded_by_ref', 64);
            $table->timestamps();

            $table->unique(['counterparty_key', 'invoice_ref'], 'cost_invoices_counterparty_ref_unique');
            $table->index(['component', 'counterparty_key', 'period_start'], 'cost_invoices_scope_idx');
            $table->index('current_status', 'cost_invoices_status_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE cost_invoices ADD CONSTRAINT cost_invoices_component_check CHECK (component IN ('provider', 'communication', 'external'))");
            DB::statement('ALTER TABLE cost_invoices ADD CONSTRAINT cost_invoices_period_check CHECK (period_end > period_start)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_invoices');
    }
};
