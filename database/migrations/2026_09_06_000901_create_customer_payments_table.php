<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E1 — customer payment IDENTITY and immutable facts.
 *
 *  - amount / currency / received_at / gateway refs / idempotency_key never
 *    change after creation (the model refuses); the lifecycle lives in
 *    customer_payment_events, and current_status / latest_event_id are a
 *    PROJECTION updated only by the service under a row lock.
 *  - received_at is the Cash Collected instant of a payment that succeeded —
 *    an event-based fact that a later status change never rewrites.
 *  - gateway_fee_amount NULL means FEES UNKNOWN, never zero; when present the
 *    fee currency must equal the payment currency (no FX before Phase E3).
 *  - idempotency_key is mandatory and unique; (gateway, gateway_payment_ref)
 *    is unique when a real external reference exists (NULL for a manual
 *    payment without one — no invented references).
 *  - No free text: only bounded reference / reason_code / evidence_ref.
 *  - subscriber_id is a historical reference without FK; user_id is the live
 *    FK (nulled on delete) so a payment never loses its owner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('subscriber_id');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('gateway', 32);
            $table->string('gateway_payment_ref', 191)->nullable();
            $table->string('idempotency_key', 191)->unique();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->decimal('gateway_fee_amount', 12, 2)->nullable();
            $table->string('fee_currency', 3)->nullable();
            $table->timestamp('received_at', 6);
            $table->string('reference', 64)->nullable();
            $table->string('reason_code', 32)->nullable();
            $table->string('evidence_ref', 191)->nullable();
            $table->string('current_status', 32);
            $table->unsignedBigInteger('latest_event_id')->nullable();
            $table->string('recorded_by_ref', 64);
            $table->timestamps();

            $table->unique(['gateway', 'gateway_payment_ref'], 'customer_payments_gateway_ref_unique');
            $table->index(['subscriber_id', 'received_at'], 'customer_payments_subscriber_received_idx');
            $table->index('received_at', 'customer_payments_received_idx');
            $table->index('current_status', 'customer_payments_status_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE customer_payments ADD CONSTRAINT customer_payments_amount_positive_check CHECK (amount > 0)');
            DB::statement('ALTER TABLE customer_payments ADD CONSTRAINT customer_payments_fee_check CHECK ((gateway_fee_amount IS NULL AND fee_currency IS NULL) OR (gateway_fee_amount >= 0 AND fee_currency = currency))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_payments');
    }
};
