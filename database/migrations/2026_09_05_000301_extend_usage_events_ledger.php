<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B1 — usage_events becomes the general usage/cost LEDGER.
 *
 * Additive and backward-compatible: every new column is nullable or defaulted,
 * existing columns keep their meaning (`type` = usage dimension, `cost` stays as
 * a compatibility mirror of `total_cost`). The table may already hold rows in
 * production, so the final step back-fills the derived columns for them.
 *
 * Historical references are plain ids, NOT foreign keys:
 *  - subscriber_id: immutable attribution of the cost to the subscriber who
 *    caused it. user_id (existing FK) is nulled when a user is hard-deleted;
 *    the snapshot keeps the pseudonymous internal id so history never loses
 *    its owner, without retaining any personal data.
 *  - subscription_id / plan_id (+ plan_slug snapshot): the ledger must keep
 *    saying "this cost happened while the subscriber was on Plus" even after the
 *    subscription row is cascaded away with the user or the plan is changed on
 *    the same subscription row (upgrade/downgrade mutates subscriptions.plan_id).
 *  - job_ref / job_step_ref / tool_invocation_ref: correlation to tables that do
 *    not exist yet; real relations can be added by their own migration later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usage_events', function (Blueprint $table) {
            // Who / under which subscription & plan (snapshots, no FK).
            $table->unsignedBigInteger('subscriber_id')->nullable()->after('user_id');
            $table->unsignedBigInteger('subscription_id')->nullable()->after('subscriber_id');
            $table->unsignedBigInteger('plan_id')->nullable()->after('subscription_id');
            $table->string('plan_slug')->nullable()->after('plan_id');

            // What: one logical request may produce several billable invocations.
            $table->string('correlation_id')->nullable()->after('idempotency_key');
            $table->string('operation')->nullable()->after('type');
            $table->string('channel')->nullable()->after('operation');
            // Explicitly written by the recorder for new rows; NULL = unknown for
            // rows that pre-date the ledger (never assume they succeeded).
            $table->string('outcome')->nullable()->after('channel');

            // How much.
            $table->unsignedBigInteger('cached_units')->default(0)->after('output_units');
            $table->unsignedInteger('duration_ms')->nullable()->after('quantity');

            // Cost components; total_cost is authoritative, `cost` mirrors it.
            $table->decimal('provider_cost', 12, 6)->default(0)->after('cost');
            $table->decimal('communication_cost', 12, 6)->default(0)->after('provider_cost');
            $table->decimal('external_cost', 12, 6)->default(0)->after('communication_cost');
            $table->decimal('total_cost', 12, 6)->default(0)->after('external_cost');

            // When the operation actually happened (created_at = when it was written).
            $table->timestamp('occurred_at')->nullable()->after('metadata');

            // Future correlation (no FK — targets do not exist yet).
            $table->string('job_ref')->nullable()->after('occurred_at');
            $table->string('job_step_ref')->nullable()->after('job_ref');
            $table->string('tool_invocation_ref')->nullable()->after('job_step_ref');

            $table->index(['user_id', 'occurred_at'], 'usage_events_user_occurred_idx');
            $table->index(['subscriber_id', 'occurred_at'], 'usage_events_subscriber_occurred_idx');
            $table->index('subscription_id', 'usage_events_subscription_idx');
            $table->index('correlation_id', 'usage_events_correlation_idx');
            $table->index('operation', 'usage_events_operation_idx');
            $table->index('job_ref', 'usage_events_job_ref_idx');
        });

        // Rows written before this ledger existed: derive only what is certain.
        //  - occurred_at ≈ created_at.
        //  - The legacy `cost` was a generic per-dimension "service cost" (it also
        //    covered WhatsApp dimensions), so it is the TOTAL — never re-classified
        //    as provider_cost; the component split stays 0 (unattributed).
        //  - outcome and operation stay NULL (unknown) — never assumed.
        //  - subscriber_id snapshot from user_id where the user still exists;
        //    rows already nulled by earlier deletions cannot be recovered.
        DB::table('usage_events')
            ->whereNull('occurred_at')
            ->update([
                'occurred_at' => DB::raw('created_at'),
                'total_cost' => DB::raw('cost'),
            ]);

        DB::table('usage_events')
            ->whereNull('subscriber_id')
            ->whereNotNull('user_id')
            ->update(['subscriber_id' => DB::raw('user_id')]);
    }

    public function down(): void
    {
        Schema::table('usage_events', function (Blueprint $table) {
            $table->dropIndex('usage_events_user_occurred_idx');
            $table->dropIndex('usage_events_subscriber_occurred_idx');
            $table->dropIndex('usage_events_subscription_idx');
            $table->dropIndex('usage_events_correlation_idx');
            $table->dropIndex('usage_events_operation_idx');
            $table->dropIndex('usage_events_job_ref_idx');

            $table->dropColumn([
                'subscriber_id', 'subscription_id', 'plan_id', 'plan_slug',
                'correlation_id', 'operation', 'channel', 'outcome',
                'cached_units', 'duration_ms',
                'provider_cost', 'communication_cost', 'external_cost', 'total_cost',
                'occurred_at', 'job_ref', 'job_step_ref', 'tool_invocation_ref',
            ]);
        });
    }
};
