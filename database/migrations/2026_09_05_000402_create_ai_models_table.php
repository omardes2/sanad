<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B2 — routable models per provider, with capability metadata and an
 * optional fallback relation (self-reference, may cross providers).
 *
 *  - external_id: the id sent to the provider (e.g. gpt-4.1-mini).
 *  - aliases: other ids the provider may REPORT for the same model (e.g. dated
 *    snapshots such as gpt-4.1-mini-2025-04-14), so ledger rows resolve to the
 *    right model and price.
 *  - capabilities / supports_tools / context_window / max_output_tokens are
 *    metadata; the router uses `capabilities` only in B2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained('ai_providers')->restrictOnDelete();
            $table->string('external_id');
            $table->string('name');
            $table->json('aliases')->nullable();
            $table->json('capabilities')->nullable();
            $table->boolean('supports_tools')->default(false);
            $table->unsignedInteger('context_window')->nullable();
            $table->unsignedInteger('max_output_tokens')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->integer('priority')->default(0);
            $table->foreignId('fallback_model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['provider_id', 'external_id'], 'ai_models_provider_external_unique');
            $table->index(['is_enabled', 'priority'], 'ai_models_enabled_priority_idx');
        });

        // A model cannot be its own fallback (SQLite cannot add a constraint
        // after creation; the PriceBook/catalog services enforce it in code too).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE ai_models ADD CONSTRAINT ai_models_fallback_not_self_check CHECK (fallback_model_id IS NULL OR fallback_model_id <> id)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_models');
    }
};
