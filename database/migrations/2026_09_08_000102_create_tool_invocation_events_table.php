<?php

declare(strict_types=1);

use App\Enums\ToolInvocationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase F2 — the APPEND-ONLY history of how an invocation reached its status.
 *
 * One row per persisted transition, written in the same transaction as the
 * projection update, so the two can never disagree: the latest `to_status` is
 * the projection's `status`, and `seq` counts up to the projection's `version`.
 * `(tool_invocation_id, seq)` is unique, so two writers can never record the
 * same step twice.
 *
 * There is no `updated_at` and no update or delete path in the application. A
 * claim outcome that changed nothing — replay, in flight, conflict — writes no
 * row here at all, because no state moved.
 *
 * `detail` carries bounded machine facts only (codes, ids, hashes); never a
 * tool argument, a tool result, a message body, or anything personal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tool_invocation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tool_invocation_id')->constrained('tool_invocations')->cascadeOnDelete();
            $table->unsignedInteger('seq');
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);
            $table->string('reason_code', 32)->nullable();
            $table->string('actor_ref', 64);
            $table->json('detail')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at');

            $table->unique(['tool_invocation_id', 'seq'], 'tool_invocation_events_invocation_seq_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            $states = "'".implode("', '", ToolInvocationStatus::values())."'";

            DB::statement('ALTER TABLE tool_invocation_events ADD CONSTRAINT tool_invocation_events_seq_check CHECK (seq >= 1)');
            DB::statement("ALTER TABLE tool_invocation_events ADD CONSTRAINT tool_invocation_events_to_status_check CHECK (to_status IN ({$states}))");
            DB::statement("ALTER TABLE tool_invocation_events ADD CONSTRAINT tool_invocation_events_from_status_check CHECK (from_status IS NULL OR from_status IN ({$states}))");
            // The first event is the claim; every later one states where it came from.
            DB::statement('ALTER TABLE tool_invocation_events ADD CONSTRAINT tool_invocation_events_first_check CHECK ((seq = 1) = (from_status IS NULL))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tool_invocation_events');
    }
};
