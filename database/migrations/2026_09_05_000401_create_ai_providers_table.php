<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B2 — AI providers as DATA (operational registry, managed from Sanad
 * Admin in Phase C). Deliberately holds NO secret: credentials stay in the
 * environment (config/ai.php) until encrypted credential storage lands in
 * Phase C; `credentials_ref` only names the env variable a row expects.
 *
 * `is_primary` is stored for the Phase C cutover to DB-controlled routing; in
 * B2 the operational preference stays AI_PROVIDER (the router does not read it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->id();
            // Must match the key AiManager knows (openai, groq, ...).
            $table->string('key')->unique();
            $table->string('name');
            // Implementation family (same as key today; OpenAI-compatible
            // endpoints may share a driver later).
            $table->string('driver');
            // Optional endpoint override, stored for Phase C; not applied in B2.
            $table->string('base_url')->nullable();
            // Name of the env variable holding the key — never the key itself.
            $table->string('credentials_ref')->nullable();
            // list<AiOperation> the provider can serve.
            $table->json('capabilities')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_primary')->default(false);
            $table->integer('priority')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['is_enabled', 'priority'], 'ai_providers_enabled_priority_idx');
        });

        // At most ONE primary provider (partial unique index works on both
        // PostgreSQL and SQLite).
        DB::statement('CREATE UNIQUE INDEX ai_providers_primary_unique ON ai_providers (is_primary) WHERE is_primary = true');
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_providers');
    }
};
