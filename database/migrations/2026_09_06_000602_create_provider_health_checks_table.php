<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provider health history (Phase C3). One row per probe: what was checked,
 * with which credential source, the outcome and a SAFE error summary
 * (class/code only — never a response body, URL with credentials, or secret).
 * A billable inference probe links the ledger row it produced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_health_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_id')->constrained('ai_providers')->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('trigger', 16);
            $table->string('status', 16);
            $table->foreignId('credential_id')->nullable()->constrained('provider_credentials')->nullOnDelete();
            $table->string('credential_source', 16)->default('none');
            $table->boolean('candidate_base_url')->default(false);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_class', 191)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->boolean('cost_incurred')->default(false);
            $table->unsignedBigInteger('usage_event_id')->nullable();
            $table->string('checked_by_ref', 64)->nullable();
            $table->json('details')->nullable();
            $table->timestamp('checked_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['provider_id', 'checked_at'], 'provider_health_checks_provider_checked_idx');
            $table->index('checked_at', 'provider_health_checks_checked_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_health_checks');
    }
};
