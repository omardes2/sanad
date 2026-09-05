<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the existing usage_events ledger for the metering engine:
 *  - idempotency_key: unique, so a duplicate webhook / job retry records (and
 *    charges) a usage event at most once.
 *  - quantity: units consumed this event (weighted credits later), separate
 *    from the AI-token input/output_units already on the table.
 *  - currency: currency of the recorded cost (rates are configurable, never
 *    hard-coded), completing the revenue − cost = margin foundation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usage_events', function (Blueprint $table) {
            $table->string('idempotency_key')->nullable()->unique()->after('type');
            $table->unsignedInteger('quantity')->default(1)->after('output_units');
            $table->string('currency', 3)->nullable()->after('cost');
        });
    }

    public function down(): void
    {
        Schema::table('usage_events', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['idempotency_key', 'quantity', 'currency']);
        });
    }
};
