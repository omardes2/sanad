<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase F1 — durable SUBSCRIBER CAPABILITY CONSENT: one row per (subscriber,
 * capability), and that pair is unique. It is deliberately NOT per tool: a
 * subscriber consents to a capability ("create reminders"), and every version
 * of every tool needing that capability is covered by the one decision.
 *
 * The row is the CURRENT state only — `status` with the moment it was granted
 * and the moment it was revoked, plus `version` as the concurrency contract
 * (a mutation states the version it saw; a mismatch is stale and writes
 * nothing). The immutable history of every mutation is the audit log, so no
 * events table is needed at this phase.
 *
 * NO ROW is not a state in this table: a capability with no row reads as NOT
 * GRANTED, and only an explicit grant ever creates one.
 *
 * Nothing here is personal data: `capability` is an enum value, `reason_code`
 * is a closed list and `evidence_ref` is a bounded reference the service
 * refuses to accept an email or a phone number into.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tool_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->constrained('users')->cascadeOnDelete();
            $table->string('capability', 64);
            $table->string('status', 16);
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('reason_code', 32);
            $table->string('evidence_ref', 191)->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->string('updated_by_ref', 64);
            $table->timestamps();

            $table->unique(['subscriber_id', 'capability'], 'tool_consents_subscriber_capability_unique');
            $table->index(['capability', 'status'], 'tool_consents_capability_status_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            // The state machine, enforced by the database and not only by the service.
            DB::statement("ALTER TABLE tool_consents ADD CONSTRAINT tool_consents_status_check CHECK (status IN ('granted', 'revoked'))");
            DB::statement("ALTER TABLE tool_consents ADD CONSTRAINT tool_consents_granted_at_check CHECK (status <> 'granted' OR granted_at IS NOT NULL)");
            DB::statement("ALTER TABLE tool_consents ADD CONSTRAINT tool_consents_revoked_at_check CHECK (status <> 'revoked' OR revoked_at IS NOT NULL)");
            DB::statement('ALTER TABLE tool_consents ADD CONSTRAINT tool_consents_version_check CHECK (version >= 1)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tool_consents');
    }
};
