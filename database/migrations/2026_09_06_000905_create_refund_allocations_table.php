<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E1 — attribution of a refund to the payment allocation(s) it reverses,
 * append-only. The original payment_allocations rows are never modified;
 * Σ refund_allocations of a refund ≤ the refund amount and Σ on one allocation
 * ≤ that allocation's amount, same currency, under the payment row lock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_refund_id')->constrained('customer_refunds')->restrictOnDelete();
            $table->foreignId('payment_allocation_id')->constrained('payment_allocations')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->timestamp('allocated_at', 6);
            $table->string('actor_ref', 64);
            $table->string('reason_code', 32)->nullable();
            $table->timestamp('created_at');

            $table->index('customer_refund_id', 'refund_allocations_refund_idx');
            $table->index('payment_allocation_id', 'refund_allocations_allocation_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE refund_allocations ADD CONSTRAINT refund_allocations_amount_positive_check CHECK (amount > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_allocations');
    }
};
