<?php

use App\Enums\MemoryProvenance;
use App\Support\Memory\MemoryFingerprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable personal memory (Phase G) — the ONLY migration of the phase, and it
 * is additive.
 *
 * `memories` has existed since Sprint 0 with a full shape and NO WRITER. This
 * adds the two columns a writer cannot be correct without, and the two indexes
 * the two new query patterns need:
 *
 *   fingerprint  a keyed MAC of the normalised content. UNIQUE per
 *                (user_id, category) so the DATABASE — not a read-then-write —
 *                decides that two concurrent saves of the same memory are one
 *                memory. NULL is unconstrained on both engines, which is what
 *                lets an ARCHIVED row keep its content while releasing its
 *                slot: forget a memory, save it again, and the second save is
 *                free.
 *
 *   provenance   how the memory came to exist. V1 writes only `explicit`;
 *                `inferred` has no writer and exists so the rule "an inferred
 *                memory never overwrites an explicit one" has a column to be
 *                stated on before any extraction phase is built.
 *
 * Categories are NOT a database enum (ADR-0013): `category` stays a string and
 * `MemoryCategory` is the closed list in PHP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memories', function (Blueprint $table) {
            $table->string('fingerprint', MemoryFingerprint::LENGTH)->nullable()->after('content');
            $table->string('provenance', 16)->default(MemoryProvenance::Explicit->value)->after('importance');

            // One active memory per (subscriber, category, fingerprint).
            $table->unique(['user_id', 'category', 'fingerprint'], 'memories_user_category_fingerprint_unique');

            // The prompt contributor's selection, which runs on every AI reply:
            // one subscriber's active rows, most important first.
            $table->index(['user_id', 'archived_at', 'importance'], 'memories_user_active_importance_idx');
        });
    }

    public function down(): void
    {
        Schema::table('memories', function (Blueprint $table) {
            $table->dropUnique('memories_user_category_fingerprint_unique');
            $table->dropIndex('memories_user_active_importance_idx');
            $table->dropColumn(['fingerprint', 'provenance']);
        });
    }
};
