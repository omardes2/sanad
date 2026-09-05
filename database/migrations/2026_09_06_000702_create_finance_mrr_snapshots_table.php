<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase D1 — daily, self-contained MRR snapshots (Calculated, never Collected).
 *
 * The platform has no subscription history and no plan-price history, so the
 * only honest historical revenue figure is one captured AT THE TIME: each row
 * freezes what a plan cost and how many subscriptions sat on it on that UTC
 * day. Rows are written only by `sanad:finance:snapshot` for the CURRENT UTC
 * date (no back-dating, no back-fill) and are never updated afterwards.
 *
 *  - plan_id is a historical reference, deliberately NOT a foreign key: a plan
 *    deleted later must not delete or block financial history. plan_key is the
 *    non-null grouping key ("<plan_id>" or "none" for active subscriptions
 *    without a plan, which carry currency XXX = ISO 4217 "no currency").
 *  - plan_slug / plan_price / billing_period are the values in force when the
 *    row was captured; mrr_normalized is the plan's monthly-equivalent price ×
 *    active_count under calculation_version.
 *  - The unique key makes a same-day re-run a no-op instead of a rewrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_mrr_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('snapshot_date');
            $table->timestamp('captured_at');
            $table->string('currency', 3);
            $table->unsignedBigInteger('plan_id')->nullable();
            $table->string('plan_key', 32);
            $table->string('plan_slug')->nullable();
            $table->decimal('plan_price', 10, 2)->nullable();
            $table->string('billing_period', 16)->nullable();
            $table->unsignedInteger('active_count')->default(0);
            $table->unsignedInteger('trialing_count')->default(0);
            $table->unsignedInteger('past_due_count')->default(0);
            $table->decimal('mrr_normalized', 12, 6)->default(0);
            $table->unsignedSmallInteger('calculation_version');
            $table->timestamps();

            $table->unique(['snapshot_date', 'currency', 'plan_key'], 'finance_mrr_snapshots_day_unique');
            $table->index('snapshot_date', 'finance_mrr_snapshots_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_mrr_snapshots');
    }
};
