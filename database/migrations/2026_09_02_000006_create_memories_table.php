<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category');
            $table->text('content');
            // Importance 1..5, enforced at the application layer (see Memory model).
            $table->unsignedTinyInteger('importance')->default(3);
            $table->foreignId('source_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'category']);
            $table->index(['user_id', 'archived_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memories');
    }
};
