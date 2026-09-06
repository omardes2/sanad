<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E4 — the close projection: one row per (calendar month UTC,
 * reporting currency), unique. It is the FOR UPDATE target and carries only
 * the state (open | closed), the pointer to the latest close record and a
 * version. Every close / reopen moves it inside one transaction with an
 * audit entry; the history lives in the append-only finance_period_closes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_period_close_scopes', function (Blueprint $table) {
            $table->id();
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->string('reporting_currency', 3);
            $table->string('state', 8);
            $table->unsignedBigInteger('current_close_id')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->string('updated_by_ref', 64)->nullable();
            $table->timestamps();

            $table->unique(['period_start', 'reporting_currency'], 'finance_period_close_scopes_scope_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE finance_period_close_scopes ADD CONSTRAINT finance_period_close_scopes_state_check CHECK (state IN ('open', 'closed'))");
            DB::statement('ALTER TABLE finance_period_close_scopes ADD CONSTRAINT finance_period_close_scopes_period_check CHECK (period_end > period_start)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_period_close_scopes');
    }
};
