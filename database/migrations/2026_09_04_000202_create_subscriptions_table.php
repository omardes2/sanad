<?php

declare(strict_types=1);

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One current subscription per subscriber (a non-admin User). Attached to the
 * SUBSCRIBER, not a channel account, so the same subscription works across all
 * future channels (app, web, calls). Provider columns are nullable now so a
 * payment gateway can be linked later without a domain change.
 *
 * The unique(subscriber_id) + "create only if absent" rule is what prevents
 * repeated onboarding from granting multiple free trials.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();

            $table->string('status')->default(SubscriptionStatus::Trialing->value);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('renews_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // Future payment-gateway linkage (unused in this phase).
            $table->string('provider')->nullable();
            $table->string('provider_subscription_id')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('current_period_end');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
