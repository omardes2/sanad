<?php

use App\Enums\ChannelAccountStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel'); // App\Enums\ChannelType
            $table->string('external_identifier'); // e.g. WhatsApp phone/wa_id
            $table->string('display_name')->nullable();
            $table->json('metadata')->nullable();
            $table->string('status')->default(ChannelAccountStatus::Active->value);
            $table->timestamps();

            // The same external identifier cannot repeat within a channel.
            $table->unique(['channel', 'external_identifier']);
            $table->index(['user_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_accounts');
    }
};
