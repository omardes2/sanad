<?php

use App\Enums\MessageProcessingStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('direction'); // App\Enums\MessageDirection
            $table->string('type'); // App\Enums\MessageType
            $table->string('external_message_id')->nullable();
            $table->text('text_content')->nullable();
            $table->string('media_path')->nullable();
            $table->json('metadata')->nullable();
            $table->string('processing_status')->default(MessageProcessingStatus::Received->value);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            // The same provider message must never be processed twice.
            // Nullable column: multiple NULLs are allowed (outbound/local messages).
            $table->unique('external_message_id');
            $table->index(['conversation_id', 'created_at']);
            $table->index(['direction', 'processing_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
