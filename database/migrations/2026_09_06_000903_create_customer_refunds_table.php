<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E1 — refunds, immutable facts, append-only. Partial refunds are
 * supported; the service guarantees Σ refunds ≤ the succeeded payment amount
 * under the payment row lock, the same currency, and refunded_at ≥ the
 * payment's received_at. refunded_at is the instant a refund counts against
 * cash collected. No free text: bounded reason_code / evidence_ref only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_payment_id')->constrained('customer_payments')->restrictOnDelete();
            $table->string('gateway', 32);
            $table->string('gateway_refund_ref', 191)->nullable();
            $table->string('idempotency_key', 191)->unique();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->timestamp('refunded_at', 6);
            $table->string('reason_code', 32);
            $table->string('evidence_ref', 191)->nullable();
            $table->string('recorded_by_ref', 64);
            $table->timestamp('created_at');

            $table->unique(['gateway', 'gateway_refund_ref'], 'customer_refunds_gateway_ref_unique');
            $table->index('customer_payment_id', 'customer_refunds_payment_idx');
            $table->index('refunded_at', 'customer_refunds_refunded_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE customer_refunds ADD CONSTRAINT customer_refunds_amount_positive_check CHECK (amount > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_refunds');
    }
};
