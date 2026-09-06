<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E3 — the revision scope of ONE reporting conversion:
 * (subject_type, subject_id, purpose, target_currency) unique, carrying only
 * the current conversion pointer (+ version). FOR UPDATE target; the
 * conversions themselves are append-only, so a correction is a new revision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fx_conversion_scopes', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id');
            $table->string('purpose', 24);
            $table->string('target_currency', 3);
            $table->unsignedBigInteger('current_conversion_id')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->string('updated_by_ref', 64)->nullable();
            $table->timestamps();

            $table->unique(['subject_type', 'subject_id', 'purpose', 'target_currency'], 'fx_conversion_scopes_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_conversion_scopes');
    }
};
