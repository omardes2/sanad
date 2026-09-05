<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Provider credentials at rest (Phase C3). The secret is stored ONLY as
 * `ciphertext` sealed by CredentialVault (AES-256-GCM, CREDENTIALS_KEY);
 * `key_id` names the master key that sealed it so a master-key rotation can
 * find rows to re-encrypt. `fingerprint` (16 hex of SHA-256) and `last4` are
 * the only display forms. Lifecycle pending → active → revoked; rows are
 * never deleted, and at most ONE row per provider is active.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_id')->constrained('ai_providers')->restrictOnDelete();
            $table->string('label', 120)->nullable();
            $table->string('kind', 32)->default('api_key');
            $table->text('ciphertext');
            $table->string('key_id', 16);
            $table->string('fingerprint', 32);
            $table->string('last4', 8);
            $table->string('status', 16)->default('pending');
            $table->foreignId('rotated_from_id')->nullable()->constrained('provider_credentials')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_ref', 64)->nullable();
            $table->string('revoked_by_ref', 64)->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();

            $table->index(['provider_id', 'status'], 'provider_credentials_provider_status_idx');
            $table->index('key_id', 'provider_credentials_key_id_idx');
        });

        // At most ONE active credential per provider (partial unique index;
        // same technique as ai_providers_primary_unique — PostgreSQL + SQLite).
        DB::statement("CREATE UNIQUE INDEX provider_credentials_one_active_per_provider ON provider_credentials (provider_id) WHERE status = 'active'");
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_credentials');
    }
};
