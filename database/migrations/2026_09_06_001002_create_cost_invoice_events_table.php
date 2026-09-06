<?php

declare(strict_types=1);

use App\Enums\CostInvoiceEventType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E2 — the append-only invoice lifecycle: draft → confirmed → voided |
 * superseded. Every mutation: lock → expected state token → event →
 * projection → audit, one transaction. A partial unique index allows ONE
 * `confirmed` event per invoice on both engines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_invoice_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cost_invoice_id')->constrained('cost_invoices')->restrictOnDelete();
            $table->string('event_type', 16);
            $table->timestamp('occurred_at', 6);
            $table->string('actor_ref', 64);
            $table->string('reason_code', 32)->nullable();
            $table->string('evidence_ref', 191)->nullable();
            $table->timestamp('created_at');

            $table->index(['cost_invoice_id', 'id'], 'cost_invoice_events_invoice_idx');
        });

        DB::statement("CREATE UNIQUE INDEX cost_invoice_events_one_confirmation ON cost_invoice_events (cost_invoice_id) WHERE event_type = 'confirmed'");

        if (DB::getDriverName() === 'pgsql') {
            $types = implode("', '", array_map(static fn (CostInvoiceEventType $t): string => $t->value, CostInvoiceEventType::cases()));
            DB::statement("ALTER TABLE cost_invoice_events ADD CONSTRAINT cost_invoice_events_type_check CHECK (event_type IN ('{$types}'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_invoice_events');
    }
};
