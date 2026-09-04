<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds an admin capability to the existing users table. Dashboard access is
 * gated on this flag; no separate roles table is introduced (single-tenant
 * operator console for now). Additive and safe for existing rows: every
 * current user defaults to a non-admin, so no one gains access implicitly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false);
            $table->index('is_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_admin']);
            $table->dropColumn('is_admin');
        });
    }
};
