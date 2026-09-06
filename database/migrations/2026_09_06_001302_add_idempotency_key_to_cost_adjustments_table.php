<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E5.2b — durable, service-level idempotency for post-reconciliation
 * adjustments. Every NEW cost_adjustments row is written under a caller-
 * supplied opaque idempotency key (CostReconciliationService::adjust refuses
 * a write without one). The column is nullable ONLY so that rows written
 * before E5.2b stay valid without a backfill: the unique index is the
 * authority against a second row for the same key (same key + same facts ⇒
 * the existing adjustment; different facts ⇒ conflict). Nothing is updated,
 * deleted or backfilled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cost_adjustments', function (Blueprint $table) {
            $table->string('idempotency_key', 191)->nullable()->unique('cost_adjustments_idempotency_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('cost_adjustments', function (Blueprint $table) {
            $table->dropUnique('cost_adjustments_idempotency_key_unique');
        });
        Schema::table('cost_adjustments', function (Blueprint $table) {
            $table->dropColumn('idempotency_key');
        });
    }
};
