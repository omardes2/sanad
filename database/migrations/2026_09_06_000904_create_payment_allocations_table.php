<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E1 — ATTRIBUTION of collected cash to a subscription service period,
 * append-only and never rewritten (a later refund never touches it; refund
 * attribution lives in refund_allocations).
 *
 * The period is never typed by hand: it is the to_period_start / to_period_end
 * snapshot of ONE subscription_events row (E0), copied here. One payment may
 * be spread over several events; Σ allocations ≤ the payment amount, same
 * currency, only for a payment that actually succeeded. This is attribution
 * only — never revenue, never recognition (deferred beyond Phase E).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_payment_id')->constrained('customer_payments')->restrictOnDelete();
            $table->foreignId('subscription_event_id')->constrained('subscription_events')->restrictOnDelete();
            $table->unsignedBigInteger('subscription_id');
            $table->unsignedBigInteger('subscriber_id');
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->timestamp('allocated_at', 6);
            $table->string('actor_ref', 64);
            $table->string('reason_code', 32)->nullable();
            $table->timestamp('created_at');

            $table->index('customer_payment_id', 'payment_allocations_payment_idx');
            $table->index(['subscription_id', 'period_start'], 'payment_allocations_subscription_period_idx');
            $table->index(['subscriber_id', 'period_start'], 'payment_allocations_subscriber_period_idx');
            $table->index('period_start', 'payment_allocations_period_start_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocations_amount_positive_check CHECK (amount > 0)');
            DB::statement('ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocations_period_check CHECK (period_end > period_start)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
