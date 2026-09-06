<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E5.2a — durable, service-level idempotency for cash attribution.
 *
 * Every NEW payment_allocations / refund_allocations row is written under a
 * caller-supplied opaque idempotency key (AllocationService refuses a write
 * without one). The column is nullable ONLY so that historical rows written
 * before E5.2a stay valid without a backfill: the database unique index is
 * the authority against a second row for the same key (same key + same
 * facts ⇒ the existing row is returned; different facts ⇒ conflict).
 * Nothing is updated, deleted or backfilled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->string('idempotency_key', 191)->nullable()->unique('payment_allocations_idempotency_key_unique');
        });

        Schema::table('refund_allocations', function (Blueprint $table) {
            $table->string('idempotency_key', 191)->nullable()->unique('refund_allocations_idempotency_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->dropUnique('payment_allocations_idempotency_key_unique');
        });
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->dropColumn('idempotency_key');
        });

        Schema::table('refund_allocations', function (Blueprint $table) {
            $table->dropUnique('refund_allocations_idempotency_key_unique');
        });
        Schema::table('refund_allocations', function (Blueprint $table) {
            $table->dropColumn('idempotency_key');
        });
    }
};
