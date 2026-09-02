<?php

use App\Enums\ReplyMode;
use App\Enums\UserStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // Contact identifiers. WhatsApp-first, so phone is the primary key
            // for identifying a returning user. Both nullable + unique so a
            // user may exist with only a phone (WhatsApp) or only an email.
            $table->string('phone')->nullable()->unique();   // E.164, e.g. +970599123456
            $table->string('email')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();

            // Nullable: WhatsApp users are not required to set a password.
            $table->string('password')->nullable();

            // Localization / preferences.
            $table->string('timezone')->default(config('sanad.default_user_timezone'));
            $table->string('locale', 8)->default(config('sanad.default_locale', 'ar'));
            $table->string('currency', 3)->default(config('sanad.default_currency', 'ILS'));
            $table->string('preferred_reply_mode')->default(ReplyMode::Auto->value);

            // Lifecycle.
            $table->string('status')->default(UserStatus::Pending->value);
            $table->timestamp('onboarding_completed_at')->nullable();

            $table->rememberToken();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
