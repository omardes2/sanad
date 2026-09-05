<?php

declare(strict_types=1);

use App\Enums\BillingPeriod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subscription plans, fully admin-managed. Prices and usage limits are DATA,
 * never hard-coded in application logic:
 *  - `limits`   : { "<dimension>": { "daily": ?int, "monthly": ?int, "weight": int } }
 *                 null cap = unlimited for that window; a dimension absent = not entitled.
 *  - `features` : arbitrary entitlement flags for non-metered capabilities.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            $table->decimal('price', 10, 2)->default(0);
            $table->string('currency', 3)->default(config('sanad.default_currency', 'ILS'));
            $table->string('billing_period')->default(BillingPeriod::Monthly->value);
            $table->unsignedInteger('trial_days')->default(0);

            // Metered limits + non-metered entitlement flags (see class doc).
            $table->json('limits')->nullable();
            $table->json('features')->nullable();

            $table->boolean('is_active')->default(true);
            // The plan auto-assigned on onboarding (at most one). Enforced in code.
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
            $table->index('is_default');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
