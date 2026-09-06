<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E3 — the revision scope of ONE point-in-time quote: (pair, rate_date)
 * unique. It is the FOR UPDATE target and carries only the pointer to the
 * current rate revision (+ version); fx_rates stays append-only. There is no
 * validity interval anywhere: a rate is a quote for its date and nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fx_rate_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fx_pair_id')->constrained('fx_pairs')->restrictOnDelete();
            $table->date('rate_date');
            $table->unsignedBigInteger('current_rate_id')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->string('updated_by_ref', 64)->nullable();
            $table->timestamps();

            $table->unique(['fx_pair_id', 'rate_date'], 'fx_rate_scopes_pair_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_rate_scopes');
    }
};
