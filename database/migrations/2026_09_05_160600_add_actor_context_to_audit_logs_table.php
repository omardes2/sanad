<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase C0 — audit_logs gains request/actor context. Additive and nullable:
 * existing rows keep NULLs. The table stays append-only (created_at only).
 *
 *  - actor: how the action was initiated — "user" (authenticated request),
 *    "console" (artisan), "system" (queue/scheduler). NULL for legacy rows.
 *  - actor_ref: immutable, non-personal snapshot of the actor ("user:{id}",
 *    "console", "system"). user_id is a live FK that is nulled when the
 *    account is hard-deleted; actor_ref keeps the internal id so history
 *    never loses its author. No PII is stored.
 *  - ip_address / user_agent: request context when available.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('actor', 16)->nullable()->after('user_id');
            $table->string('actor_ref', 64)->nullable()->after('actor');
            $table->string('ip_address', 45)->nullable()->after('metadata');
            $table->string('user_agent', 512)->nullable()->after('ip_address');

            $table->index(['action', 'created_at'], 'audit_logs_action_created_idx');
            $table->index('actor_ref', 'audit_logs_actor_ref_idx');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_action_created_idx');
            $table->dropIndex('audit_logs_actor_ref_idx');
            $table->dropColumn(['actor', 'actor_ref', 'ip_address', 'user_agent']);
        });
    }
};
