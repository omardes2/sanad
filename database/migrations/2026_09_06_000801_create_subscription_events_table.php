<?php

declare(strict_types=1);

use App\Enums\SubscriptionEventType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E0 — subscription state history, APPEND-ONLY.
 *
 * One row per transition, written inside the same transaction as the state
 * change (SubscriptionService) and the audit entry, so an event can never
 * describe a change that did not happen and no change happens without one.
 *
 *  - subscription_id / subscriber_id are historical references WITHOUT foreign
 *    keys: the subscription row is cascaded away with its user, the history
 *    is not.
 *  - from_* are NULL for a creation or a BASELINE (the state before the history
 *    existed is never invented).
 *  - effective_at is the UTC instant the transition took effect — for a
 *    baseline that is the capture instant, never a back-dated one.
 *  - from_period_* / to_period_* snapshot the service period boundaries
 *    (current_period_start/end) before and after the transition — Phase E1
 *    needs the period as it WAS at the event, not only status/plan. NULL on the
 *    from side for a creation or a baseline; never back-filled or guessed.
 *  - event_type is a fixed vocabulary (SubscriptionEventType); PostgreSQL
 *    additionally enforces it with a CHECK constraint.
 *  - baseline_key ("sub:<id>") is unique so two baseline runs cannot both
 *    baseline the same subscription (NULL for every other event).
 *  - No updated_at: rows are never updated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('subscription_id');
            $table->unsignedBigInteger('subscriber_id');
            $table->string('event_type', 32);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->unsignedBigInteger('from_plan_id')->nullable();
            $table->unsignedBigInteger('to_plan_id')->nullable();
            $table->timestamp('from_period_start')->nullable();
            $table->timestamp('from_period_end')->nullable();
            $table->timestamp('to_period_start')->nullable();
            $table->timestamp('to_period_end')->nullable();
            $table->timestamp('effective_at');
            $table->string('source', 32);
            $table->string('actor_ref', 64);
            $table->string('reason')->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->string('baseline_key', 32)->nullable()->unique();
            $table->timestamp('created_at');

            $table->index(['subscription_id', 'effective_at'], 'subscription_events_sub_effective_idx');
            $table->index(['subscriber_id', 'effective_at'], 'subscription_events_subscriber_effective_idx');
            $table->index('event_type', 'subscription_events_type_idx');
            $table->index('source', 'subscription_events_source_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            $types = implode("', '", array_map(static fn (SubscriptionEventType $t): string => $t->value, SubscriptionEventType::cases()));
            DB::statement("ALTER TABLE subscription_events ADD CONSTRAINT subscription_events_type_check CHECK (event_type IN ('{$types}'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_events');
    }
};
