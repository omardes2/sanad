<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_events', function (Blueprint $table) {
            $table->id();
            // Keep usage/cost records even if the user is deleted.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type'); // e.g. chat, transcription, embedding
            $table->string('provider'); // e.g. openai
            $table->string('model')->nullable();
            $table->unsignedBigInteger('input_units')->default(0);
            $table->unsignedBigInteger('output_units')->default(0);
            // Cost stored as an exact decimal with room for fractional-cent AI pricing.
            $table->decimal('cost', 12, 6)->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['provider', 'model']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_events');
    }
};
