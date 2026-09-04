<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Atomic per-window usage counters — the race-safe enforcement store.
 *
 * One row per (subscriber, dimension, period, period_key). The UsageEngine
 * locks these rows (SELECT ... FOR UPDATE) inside a transaction to check-and-
 * increment atomically, so concurrent workers can never push a counter past its
 * hard limit. period_key is a calendar bucket, e.g. "2026-09-04" (day) or
 * "2026-09" (month), computed in the subscriber's timezone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->constrained('users')->cascadeOnDelete();
            $table->string('dimension');
            $table->string('period', 8);       // "day" | "month"
            $table->string('period_key', 16);  // "2026-09-04" | "2026-09"
            $table->unsignedBigInteger('used')->default(0);
            $table->timestamps();

            $table->unique(['subscriber_id', 'dimension', 'period', 'period_key'], 'usage_counter_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_counters');
    }
};
