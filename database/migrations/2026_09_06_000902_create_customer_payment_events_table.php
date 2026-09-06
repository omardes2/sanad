<?php

declare(strict_types=1);

use App\Enums\CustomerPaymentEventType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E1 — the OFFICIAL payment lifecycle, append-only: created | succeeded
 * | failed | disputed | dispute_resolved. Every status mutation appends here
 * (lock → expected state → event → projection → audit, one transaction); the
 * payment row's current_status is only a projection of the latest row.
 * A manual payment is recorded as created → succeeded in one transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_payment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_payment_id')->constrained('customer_payments')->restrictOnDelete();
            $table->string('event_type', 32);
            $table->timestamp('occurred_at', 6);
            $table->string('source', 16);
            $table->string('actor_ref', 64);
            $table->string('reason_code', 32)->nullable();
            $table->string('evidence_ref', 191)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['customer_payment_id', 'id'], 'customer_payment_events_payment_idx');
            $table->index('event_type', 'customer_payment_events_type_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            $types = implode("', '", array_map(static fn (CustomerPaymentEventType $t): string => $t->value, CustomerPaymentEventType::cases()));
            DB::statement("ALTER TABLE customer_payment_events ADD CONSTRAINT customer_payment_events_type_check CHECK (event_type IN ('{$types}'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_payment_events');
    }
};
