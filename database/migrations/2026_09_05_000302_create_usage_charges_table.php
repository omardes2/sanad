<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B1 — quota consumption log owned by UsageEngine (enforcement only).
 *
 * One row per accepted quota charge. Its unique idempotency_key is what makes a
 * counter increment idempotent and race-safe: the row insert and the atomic
 * counter upsert happen in ONE transaction, so a duplicate (retry / concurrent
 * replay) loses the unique constraint and rolls the increment back.
 *
 * Deliberately separate from usage_events (the cost ledger, owned by
 * UsageRecorder): quota accounting only exists while enforcement is on, while
 * cost recording always happens — the two must never depend on each other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->constrained('users')->cascadeOnDelete();
            $table->string('dimension');
            $table->string('idempotency_key')->unique();
            $table->unsignedInteger('weight')->default(1);
            $table->timestamps();

            $table->index(['subscriber_id', 'dimension']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_charges');
    }
};
