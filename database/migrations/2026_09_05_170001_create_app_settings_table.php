<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase C1 — operational settings as DATA. One row per overridden key; a key
 * with no row means "use the config default". The type, validation rules,
 * permission and precedence of every key live in the code registry
 * (App\Support\Settings\SettingsRegistry), never in this table, so an unknown
 * key or an invalid value can never be persisted through the application.
 * No rows are inserted by this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            // JSON so the value keeps its native type (bool/int/float/string).
            $table->json('value')->nullable();
            // Who last changed it: live FK (nulled on delete) + immutable ref.
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('updated_by_ref', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
