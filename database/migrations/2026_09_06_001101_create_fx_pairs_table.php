<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E3 — ONE canonical FX pair per two currencies: pair_key =
 * min(ISO) ':' max(ISO) is unique, so a reversed pair can never exist next to
 * it. base_currency / quote_currency is the official quoting orientation
 * chosen at creation (1 BASE = rate × QUOTE); every rate of the pair uses it.
 * Identity is immutable; the row is never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fx_pairs', function (Blueprint $table) {
            $table->id();
            $table->string('pair_key', 7)->unique();
            $table->string('base_currency', 3);
            $table->string('quote_currency', 3);
            $table->string('created_by_ref', 64);
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE fx_pairs ADD CONSTRAINT fx_pairs_distinct_check CHECK (base_currency <> quote_currency)');
            DB::statement("ALTER TABLE fx_pairs ADD CONSTRAINT fx_pairs_key_check CHECK (pair_key = LEAST(base_currency, quote_currency) || ':' || GREATEST(base_currency, quote_currency))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_pairs');
    }
};
